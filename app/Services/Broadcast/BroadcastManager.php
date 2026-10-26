<?php

namespace App\Services\Broadcast;

use App\Actions\Telegram\EntitiesToHtml;
use App\DTOs\TelegramUpdateDto;
use App\Models\Broadcast;
use App\TelegramBot\TelegramMethods;
use Illuminate\Support\Facades\Log;

/**
 * Что можно сделать с уже отправленной рассылкой: исправить текст у всех клиентов или удалить её у них.
 * Правка и удаление никогда не создают новую рассылку.
 */
class BroadcastManager
{
    /**
     * Исправление текста/подписи рассылки после отправки: администратор отредактировал своё сообщение в топике рассылки
     *
     * @param TelegramUpdateDto $update
     *
     * @return string|null Отчёт для топика (null — сообщение не относится к рассылкам)
     */
    public function applyEdit(TelegramUpdateDto $update): ?string
    {
        $broadcast = Broadcast::findBySource((int)$update->chatId, (int)$update->messageId);

        if (!$broadcast) {
            return null;
        }

        if ($broadcast->status === Broadcast::STATUS_DELETED) {
            return __('messages.broadcast.edit_deleted');
        }

        $targets = $this->editTargets($broadcast, $update);

        if (is_string($targets)) {
            return $targets;
        }

        if (empty($targets['edits'])) {
            return null;
        }

        set_time_limit(280);

        $done = 0;
        $failed = 0;

        foreach ($broadcast->messages as $record) {
            $ids = array_values($record->message_ids);
            $ok = true;

            foreach ($targets['edits'] as $index => $edit) {
                if (!isset($ids[$targets['offset'] + $index])) {
                    continue;
                }

                $response = TelegramMethods::sendQueryTelegram($edit['method'], array_merge($edit['params'], [
                    'chat_id' => $record->chat_id,
                    'message_id' => $ids[$targets['offset'] + $index],
                    'parse_mode' => 'html',
                ]));

                $notModified = str_contains((string)($response->rawData['description'] ?? ''), 'message is not modified');

                if ($response->ok !== true && !$notModified) {
                    $ok = false;
                    Log::warning('BroadcastManager: не удалось исправить сообщение рассылки', [
                        'chat_id' => $record->chat_id,
                        'error' => $response->rawData['description'] ?? null,
                    ]);
                }

                usleep(40000);
            }

            $ok ? $done++ : $failed++;
        }

        return __('messages.broadcast.edited', ['done' => $done, 'failed' => $failed]);
    }

    /**
     * Что и как править у клиентов
     *
     * @param Broadcast         $broadcast
     * @param TelegramUpdateDto $update
     *
     * @return array|string ['offset' => int, 'edits' => array[]] либо текст причины, по которой править нельзя
     */
    private function editTargets(Broadcast $broadcast, TelegramUpdateDto $update): array|string
    {
        $newText = (string)($update->text ?? '');
        $newCaption = (string)($update->caption ?? '');

        if ($broadcast->kind === 'text') {
            $chunks = EntitiesToHtml::convertInChunks($newText, $update->entities);
            $existing = $this->partsCount($broadcast);

            if (count($chunks) !== $existing) {
                return __('messages.broadcast.edit_parts_changed');
            }

            return [
                'offset' => 0,
                'edits' => array_map(fn ($html) => ['method' => 'editMessageText', 'params' => ['text' => $html]], $chunks),
            ];
        }

        // Альбом: подпись принадлежит первому сообщению; правка других частей рассылку не меняет
        if ($broadcast->kind === 'album' && (int)$update->messageId !== (int)min($broadcast->source_message_ids) && $newCaption === '') {
            return ['offset' => 0, 'edits' => []];
        }

        if ($broadcast->has_follow_up) {
            // Длинная подпись ушла отдельным текстом (после вложения)
            $chunks = EntitiesToHtml::convertInChunks($newCaption, $update->entities);

            if (count($chunks) !== $this->partsCount($broadcast) - 1) {
                return __('messages.broadcast.edit_parts_changed');
            }

            return [
                'offset' => 1,
                'edits' => array_map(fn ($html) => ['method' => 'editMessageText', 'params' => ['text' => $html]], $chunks),
            ];
        }

        if (EntitiesToHtml::length($newCaption) > EntitiesToHtml::CAPTION_LIMIT) {
            return __('messages.broadcast.edit_caption_too_long');
        }

        return [
            'offset' => 0,
            'edits' => [['method' => 'editMessageCaption', 'params' => ['caption' => EntitiesToHtml::convert($newCaption, $update->entities)]]],
        ];
    }

    /**
     * Сколько сообщений получает клиент (по самому полному из записанных результатов)
     *
     * @param Broadcast $broadcast
     *
     * @return int
     */
    private function partsCount(Broadcast $broadcast): int
    {
        $counts = $broadcast->messages->map(fn ($record) => count($record->message_ids));

        return (int)($counts->max() ?? 0);
    }

    /**
     * Удаление рассылки у всех клиентов
     *
     * @param Broadcast $broadcast
     *
     * @return string Отчёт для топика
     */
    public function delete(Broadcast $broadcast): string
    {
        if ($broadcast->status === Broadcast::STATUS_DELETED) {
            return __('messages.broadcast.already_deleted');
        }

        set_time_limit(280);

        $deleted = 0;
        $failedChats = 0;
        $errors = [];

        foreach ($broadcast->messages as $record) {
            $chatOk = true;

            foreach ($record->message_ids as $messageId) {
                $response = TelegramMethods::sendQueryTelegram('deleteMessage', [
                    'chat_id' => $record->chat_id,
                    'message_id' => $messageId,
                ]);

                if ($response->ok === true) {
                    $deleted++;
                } else {
                    $chatOk = false;
                    $error = (string)($response->rawData['description'] ?? 'неизвестная ошибка');
                    $errors[$error] = ($errors[$error] ?? 0) + 1;
                }

                usleep(40000);
            }

            if (!$chatOk) {
                $failedChats++;
            }
        }

        $broadcast->update(['status' => Broadcast::STATUS_DELETED, 'deleted_at' => now()]);

        $report = __('messages.broadcast.deleted', ['deleted' => $deleted, 'failed' => $failedChats]);

        foreach ($errors as $error => $count) {
            $report .= "\n— {$count} × " . htmlspecialchars($error);
        }

        return $report;
    }
}
