<?php

namespace App\Actions\Telegram;

/**
 * Превращает текст сообщения Telegram и его entities (ссылки, жирный, курсив…) в HTML для parse_mode=html.
 *
 * Смещения и длины entities в Telegram считаются в кодовых единицах UTF-16 (эмодзи вне базовой плоскости — две
 * единицы), поэтому работаем с массивом символов и таблицей смещений. Без этого «скрытые» ссылки и форматирование
 * теряются или съезжают, как только в тексте появляется эмодзи.
 */
class EntitiesToHtml
{
    /** Запас под лимит Telegram 4096 символов в сообщении */
    public const MESSAGE_LIMIT = 4000;

    /** Лимит подписи к медиа у ботов */
    public const CAPTION_LIMIT = 1024;

    /**
     * @param string|null $text
     * @param array|null  $entities
     *
     * @return string
     */
    public static function convert(?string $text, ?array $entities): string
    {
        $text = (string)$text;
        $chars = self::chars($text);
        $units = self::unitOffsets($chars);

        return self::render($chars, 0, count($chars), self::normalize($entities ?? [], $units, count($chars)));
    }

    /**
     * Текст, разбитый на части не длиннее $limit (по UTF-16), по границам абзацев/строк/слов; форматирование сохраняется
     *
     * @param string|null $text
     * @param array|null  $entities
     * @param int         $limit
     *
     * @return string[] HTML-фрагменты
     */
    public static function convertInChunks(?string $text, ?array $entities, int $limit = self::MESSAGE_LIMIT): array
    {
        $chars = self::chars((string)$text);
        $units = self::unitOffsets($chars);
        $all = self::normalize($entities ?? [], $units, count($chars));

        $chunks = [];
        $from = 0;
        $total = count($chars);

        while ($from < $total) {
            $to = self::chunkEnd($chars, $units, $from, $limit);

            // Entity, выходящая за границы куска, обрезается по границам
            $clipped = [];
            foreach ($all as $e) {
                if ($e['end'] <= $from || $e['start'] >= $to) {
                    continue;
                }
                $e['start'] = max($e['start'], $from);
                $e['end'] = min($e['end'], $to);
                $clipped[] = $e;
            }

            $html = trim(self::render($chars, $from, $to, $clipped));
            if ($html !== '') {
                $chunks[] = $html;
            }

            $from = $to;
        }

        return $chunks;
    }

    /**
     * Длина текста в кодовых единицах UTF-16 (так её считает Telegram)
     *
     * @param string|null $text
     *
     * @return int
     */
    public static function length(?string $text): int
    {
        $units = self::unitOffsets(self::chars((string)$text));

        return end($units) ?: 0;
    }

    /**
     * @param string $text
     *
     * @return string[]
     */
    private static function chars(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Смещения символов в единицах UTF-16: units[i] — смещение i-го символа, последний элемент — общая длина
     *
     * @param string[] $chars
     *
     * @return int[]
     */
    private static function unitOffsets(array $chars): array
    {
        $units = [];
        $offset = 0;

        foreach ($chars as $i => $char) {
            $units[$i] = $offset;
            $offset += strlen($char) >= 4 ? 2 : 1;
        }

        $units[count($chars)] = $offset;

        return $units;
    }

    /**
     * Entities в индексах символов, отсортированные: сначала более ранние и более длинные
     *
     * @param array $entities
     * @param int[] $units
     * @param int   $count
     *
     * @return array
     */
    private static function normalize(array $entities, array $units, int $count): array
    {
        $byUnit = array_flip($units);
        $result = [];

        foreach ($entities as $entity) {
            $startUnit = (int)($entity['offset'] ?? 0);
            $endUnit = $startUnit + (int)($entity['length'] ?? 0);

            if (!isset($byUnit[$startUnit]) || !isset($byUnit[$endUnit]) || $endUnit <= $startUnit) {
                continue;
            }

            $result[] = [
                'start' => $byUnit[$startUnit],
                'end' => min($byUnit[$endUnit], $count),
                'type' => (string)($entity['type'] ?? ''),
                'url' => $entity['url'] ?? null,
                'user' => $entity['user']['id'] ?? null,
                'language' => $entity['language'] ?? null,
            ];
        }

        usort($result, fn ($a, $b) => [$a['start'], $b['end']] <=> [$b['start'], $a['end']]);

        return $result;
    }

    /**
     * @param string[] $chars
     * @param array    $entities Entities внутри [$from, $to), отсортированные
     *
     * @return string
     */
    private static function render(array $chars, int $from, int $to, array $entities): string
    {
        $out = '';
        $pos = $from;
        $count = count($entities);
        $i = 0;

        while ($i < $count) {
            $e = $entities[$i];

            // Пересекающаяся с предыдущей (не вложенная) entity — пропускаем
            if ($e['start'] < $pos || $e['end'] > $to) {
                $i++;
                continue;
            }

            $out .= self::escape(implode('', array_slice($chars, $pos, $e['start'] - $pos)));

            $children = [];
            $j = $i + 1;
            while ($j < $count && $entities[$j]['start'] < $e['end']) {
                if ($entities[$j]['end'] <= $e['end']) {
                    $children[] = $entities[$j];
                }
                $j++;
            }

            $out .= self::wrap($e, self::render($chars, $e['start'], $e['end'], $children), implode('', array_slice($chars, $e['start'], $e['end'] - $e['start'])));

            $pos = $e['end'];
            $i = $j;
        }

        return $out . self::escape(implode('', array_slice($chars, $pos, $to - $pos)));
    }

    /**
     * @param array  $e
     * @param string $inner Уже отрендеренное содержимое
     * @param string $plain Исходный текст entity (для pre/code)
     *
     * @return string
     */
    private static function wrap(array $e, string $inner, string $plain): string
    {
        return match ($e['type']) {
            'bold' => "<b>{$inner}</b>",
            'italic' => "<i>{$inner}</i>",
            'underline' => "<u>{$inner}</u>",
            'strikethrough' => "<s>{$inner}</s>",
            'spoiler' => "<tg-spoiler>{$inner}</tg-spoiler>",
            'code' => '<code>' . self::escape($plain) . '</code>',
            'pre' => $e['language']
                ? '<pre><code class="language-' . htmlspecialchars((string)$e['language'], ENT_QUOTES) . '">' . self::escape($plain) . '</code></pre>'
                : '<pre>' . self::escape($plain) . '</pre>',
            'blockquote' => "<blockquote>{$inner}</blockquote>",
            'expandable_blockquote' => "<blockquote expandable>{$inner}</blockquote>",
            'text_link' => $e['url'] ? '<a href="' . htmlspecialchars((string)$e['url'], ENT_QUOTES) . '">' . $inner . '</a>' : $inner,
            'text_mention' => $e['user'] ? '<a href="tg://user?id=' . (int)$e['user'] . '">' . $inner . '</a>' : $inner,
            default => $inner, // url, mention, hashtag, email, телефоны, custom_emoji — Telegram разберёт сам
        };
    }

    /**
     * @param string $text
     *
     * @return string
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES);
    }

    /**
     * Конец очередного куска: не дальше $limit единиц UTF-16, по возможности по границе абзаца, строки или слова
     *
     * @param string[] $chars
     * @param int[]    $units
     * @param int      $from
     * @param int      $limit
     *
     * @return int
     */
    private static function chunkEnd(array $chars, array $units, int $from, int $limit): int
    {
        $total = count($chars);
        $to = $from;

        while ($to < $total && $units[$to + 1] - $units[$from] <= $limit) {
            $to++;
        }

        if ($to >= $total) {
            return $total;
        }

        // Ищем границу в последней четверти куска: абзац, затем строка, затем пробел
        $floor = $from + (int)(($to - $from) * 0.75);
        foreach (["\n\n", "\n", ' '] as $separator) {
            for ($k = $to; $k > $floor; $k--) {
                $window = ($chars[$k - 2] ?? '') . ($chars[$k - 1] ?? '');
                $matches = $separator === "\n\n" ? $window === "\n\n" : ($chars[$k - 1] ?? '') === $separator;

                if ($matches) {
                    return $k;
                }
            }
        }

        return $to;
    }
}
