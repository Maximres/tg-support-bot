<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Enums\SafeCodeType;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Models\BotUser;
use App\Models\SafeCode;
use Illuminate\Support\Facades\Log;

/**
 * Установка нового значения (код сейфа/здания, ссылка на орг. информацию)
 * администратором из admin-супергруппы
 */
class SetTrustedValue
{
    /**
     * @param TelegramUpdateDto $update
     * @param SafeCodeType      $type
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update, SafeCodeType $type): void
    {
        try {
            $groupId = config('traffic_source.settings.telegram.group_id');

            $isAdmin = VerifyGroupAdmin::check((int)$groupId, $update->fromUserId);
            if ($isAdmin !== true) {
                $this->reply($groupId, $update->messageThreadId, $isAdmin === null
                    ? __('messages.command_admin_check_failed')
                    : __('messages.command_admin_only'));
                return;
            }

            $value = $this->extractValue($type, $update->text);

            if ($value === '') {
                $this->reply($groupId, $update->messageThreadId, __('messages.command_set_value_request', [
                    'command' => $type->command(),
                    'example' => $type->command() . ' ' . $type->exampleValue(),
                ]));
                return;
            }

            if (!$this->isValidValue($type, $value)) {
                $this->reply($groupId, $update->messageThreadId, $type->isUrl()
                    ? __('messages.command_set_org_link_invalid')
                    : __('messages.command_set_value_invalid'));
                return;
            }

            $current = SafeCode::current($type);
            if ($current && $current->code === $value) {
                $this->reply($groupId, $update->messageThreadId, __('messages.command_set_value_unchanged', [
                    'type' => $type->label(),
                ]));
                return;
            }

            try {
                SafeCode::create([
                    'code' => $value,
                    'type' => $type->value,
                    'created_by_chat_id' => $update->fromUserId,
                ]);
            } catch (\Throwable $e) {
                (new LokiLogger())->logException($e);
                Log::error('SetTrustedValue: ошибка при сохранении значения', [
                    'type' => $type->value,
                    'error' => $e->getMessage(),
                ]);
                $this->reply($groupId, $update->messageThreadId, __('messages.command_set_value_error'));
                return;
            }

            if ($type->isUrl()) {
                $this->reply($groupId, $update->messageThreadId, __('messages.command_set_org_link_saved'));
                return;
            }

            $this->reply($groupId, $update->messageThreadId, __('messages.command_set_value_saved', [
                'type' => $type->label(),
            ]));

            $this->notifyTrustedUsers($type);

            Log::info('SetTrustedValue: значение обновлено', [
                'type' => $type->value,
                'set_by_user_id' => $update->fromUserId,
            ]);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('SetTrustedValue: неожиданная ошибка', [
                'type' => $type->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Извлекает значение из текста команды
     *
     * @param SafeCodeType $type
     * @param string|null  $text
     *
     * @return string
     */
    private function extractValue(SafeCodeType $type, ?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        $value = preg_replace('/^' . preg_quote($type->command(), '/') . '(?:@\w+)?\s*/i', '', $text);

        return trim($value);
    }

    /**
     * Проверяет, что значение пригодно для сохранения и дальнейшей отправки в Telegram
     *
     * @param SafeCodeType $type
     * @param string       $value
     *
     * @return bool
     */
    private function isValidValue(SafeCodeType $type, string $value): bool
    {
        if (mb_strlen($value) > 256 || preg_match('/[\r\n]/', $value)) {
            return false;
        }

        if ($type->isUrl() && !preg_match('#^https?://#i', $value)) {
            return false;
        }

        return true;
    }

    /**
     * Короткое уведомление о смене значения всем доверенным активным пользователям
     * Само значение в рассылку не включается — только по нажатию кнопки
     *
     * @param SafeCodeType $type
     *
     * @return void
     */
    private function notifyTrustedUsers(SafeCodeType $type): void
    {
        $users = BotUser::where('is_banned', false)
            ->where('is_trusted', true)
            ->whereNotNull('chat_id')
            ->where('platform', 'telegram')
            ->get();

        foreach ($users as $user) {
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => $user->chat_id,
                'text' => __('messages.access_rotated', ['type' => $type->label()]),
                'parse_mode' => 'html',
            ]));
        }
    }

    /**
     * @param int|string $groupId
     * @param int|null   $messageThreadId
     * @param string     $text
     *
     * @return void
     */
    private function reply(int|string $groupId, ?int $messageThreadId, string $text): void
    {
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $groupId,
            'message_thread_id' => $messageThreadId,
            'text' => $text,
            'parse_mode' => 'html',
        ]));
    }
}
