<?php

namespace App\Actions\Telegram;

use App\TelegramBot\TelegramMethods;
use Illuminate\Support\Facades\Log;

/**
 * Подтверждение нажатия inline-кнопки: гасит "спиннер" и при необходимости
 * показывает текст, видимый только нажавшему
 */
trait AnswersCallbackQuery
{
    /**
     * @param int|null $callbackId
     * @param string   $text
     * @param bool     $showAlert true — всплывающее окно, false — короткая подсказка сверху
     *
     * @return void
     */
    protected function ack(?int $callbackId, string $text = '', bool $showAlert = false): void
    {
        if (empty($callbackId)) {
            return;
        }

        $response = TelegramMethods::sendQueryTelegram('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => mb_substr($text, 0, 200),
            'show_alert' => $showAlert,
        ]);

        if (!$response->ok) {
            Log::warning('AnswersCallbackQuery: не удалось подтвердить callback_query', [
                'callback_id' => $callbackId,
                'error' => $response->rawData['description'] ?? null,
            ]);
        }
    }
}
