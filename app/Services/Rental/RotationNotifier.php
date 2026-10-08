<?php

namespace App\Services\Rental;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotSetting;
use App\Models\BotUser;

/**
 * Сводное уведомление о ротации кодов и ссылок.
 *
 * Админ часто меняет сразу несколько значений подряд (после блокировки клиента — все). Вместо серии
 * сообщений каждому доверенному клиенту значения копятся, и планировщик отправляет ОДНО сообщение,
 * когда за последние пару минут новых замен не было. Сами значения в уведомление не попадают.
 */
class RotationNotifier
{
    private const KEY_PENDING = 'rotation.pending';

    private const KEY_LAST_AT = 'rotation.last_at';

    /** Сколько секунд без новых замен нужно, чтобы отправить сводку */
    public const QUIET_SECONDS = 120;

    /**
     * Запомнить, что значение «title» заменено
     *
     * @param string $title
     *
     * @return void
     */
    public static function record(string $title): void
    {
        $pending = self::pending();

        if (!in_array($title, $pending, true)) {
            $pending[] = $title;
        }

        BotSetting::set(self::KEY_PENDING, json_encode($pending, JSON_UNESCAPED_UNICODE));
        BotSetting::set(self::KEY_LAST_AT, (string)time());
    }

    /**
     * Вызывается планировщиком каждую минуту
     *
     * @return void
     */
    public function flushIfQuiet(): void
    {
        $pending = self::pending();

        if (empty($pending)) {
            return;
        }

        if (time() - (int)BotSetting::get(self::KEY_LAST_AT, '0') < self::QUIET_SECONDS) {
            return;
        }

        // Сначала очищаем, чтобы параллельный запуск не отправил сводку второй раз
        BotSetting::set(self::KEY_PENDING, null);

        $text = __('messages.rotation_summary', ['items' => implode(', ', $pending)]);

        BotUser::where('is_banned', false)
            ->where('is_trusted', true)
            ->whereNotNull('chat_id')
            ->where('platform', 'telegram')
            ->each(function (BotUser $user) use ($text) {
                SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                    'methodQuery' => 'sendMessage',
                    'chat_id' => $user->chat_id,
                    'text' => $text,
                    'parse_mode' => 'html',
                ]));
            });
    }

    /**
     * @return string[]
     */
    private static function pending(): array
    {
        $decoded = json_decode((string)BotSetting::get(self::KEY_PENDING, '[]'), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
