<?php

namespace App\Actions\Telegram;

use App\TelegramBot\TelegramMethods;

/**
 * Текущее фото профиля клиента (если оно у него есть и доступно боту по настройкам приватности)
 */
class GetProfilePhoto
{
    /**
     * @param int $userId
     *
     * @return string|null file_id самого большого размера
     */
    public static function execute(int $userId): ?string
    {
        $response = TelegramMethods::sendQueryTelegram('getUserProfilePhotos', [
            'user_id' => $userId,
            'limit' => 1,
        ]);

        if ($response->ok !== true) {
            return null;
        }

        $sizes = $response->rawData['result']['photos'][0] ?? [];

        if (empty($sizes)) {
            return null;
        }

        $largest = end($sizes);

        return $largest['file_id'] ?? null;
    }
}
