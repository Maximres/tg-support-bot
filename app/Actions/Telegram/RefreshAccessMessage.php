<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\TelegramBot\TelegramMethods;
use Illuminate\Support\Facades\Log;

/**
 * Приводит закреплённое меню клиента в соответствие с его текущим доступом (открыт/закрыт/заблокирован):
 * кнопки правятся на месте, без повторной отправки. Если меню нет или оно удалено — присылает новое.
 */
class RefreshAccessMessage
{
    /** 1, если при последнем вызове execute пересобраны закрепы в чате (убраны старые копии меню), иначе 0 */
    public int $removed = 0;

    /**
     * @param BotUser $botUser
     * @param bool    $notify Отправить клиенту короткое уведомление об изменении доступа
     * @param bool    $prune  Убрать из закреплённых старые копии меню (см. PruneOldMenuPins)
     * @param bool    $deep   Глубокая чистка: найти и убрать копии, лежащие под актуальным меню
     *
     * @return bool true, если у клиента теперь актуальное меню
     */
    public function execute(BotUser $botUser, bool $notify = false, bool $prune = false, bool $deep = false): bool
    {
        $this->removed = 0;

        if ($botUser->platform !== 'telegram' || empty($botUser->chat_id)) {
            return false;
        }

        // Пока клиент не принял оферту, меню у него нет: оно появится после согласия сразу с нужным доступом
        if (!$botUser->hasAcceptedOffer()) {
            return false;
        }

        $refreshed = $botUser->hasAccessMessage()
            ? $this->editInPlace($botUser)
            : false;

        if (!$refreshed && !$botUser->isBanned()) {
            // Меню нет (клиент его удалил или оно ещё не отправлялось) — присылаем новое
            (new SendAccessMessage())->execute($botUser, true);
            $refreshed = true;
        }

        if ($prune && $refreshed && !$botUser->isBanned() && $botUser->fresh()?->hasAccessMessage()) {
            $this->removed = (new PruneOldMenuPins())->execute($botUser->fresh(), $deep) ? 1 : 0;
        }

        if ($notify && !$botUser->isBanned()) {
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => $botUser->chat_id,
                'text' => $botUser->isTrusted() ? __('messages.access_granted_notice') : __('messages.access_revoked_notice'),
                'parse_mode' => 'html',
            ]));

            if ($botUser->isTrusted()) {
                SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                    'methodQuery' => 'sendMessage',
                    'chat_id' => $botUser->chat_id,
                    'text' => __('messages.access_granted_help'),
                    'parse_mode' => 'html',
                ]));
            }
        }

        return $refreshed;
    }

    /**
     * @param BotUser $botUser
     *
     * @return bool
     */
    private function editInPlace(BotUser $botUser): bool
    {
        $menu = new SendAccessMessage();

        $response = TelegramMethods::sendQueryTelegram('editMessageText', [
            'chat_id' => $botUser->chat_id,
            'message_id' => $botUser->access_message_id,
            'text' => $menu->buildText($botUser),
            'parse_mode' => 'html',
            'reply_markup' => ['inline_keyboard' => $menu->getKeyboard($botUser->hasMaterialsAccess(), $botUser->isBanned())],
        ]);

        $description = (string)($response->rawData['description'] ?? '');

        // Тот же текст и кнопки (например, повторное переключение) — Telegram отвечает ошибкой, но меню актуально
        if ($response->ok === true || str_contains($description, 'message is not modified')) {
            return true;
        }

        Log::info('RefreshAccessMessage: не удалось обновить меню на месте', [
            'bot_user_id' => $botUser->id,
            'error' => $description,
        ]);

        return false;
    }
}
