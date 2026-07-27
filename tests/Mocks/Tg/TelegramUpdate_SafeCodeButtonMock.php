<?php

namespace Tests\Mocks\Tg;

use App\DTOs\TelegramUpdateDto;
use Illuminate\Support\Facades\Request;

/**
 * Мок callback_query от нажатия кнопки доступа в личном чате сотрудника
 */
class TelegramUpdate_SafeCodeButtonMock extends TelegramUpdateDto
{
    /**
     * @param int    $chatId
     * @param string $callbackData
     *
     * @return array
     */
    public static function getDtoParams(int $chatId, string $callbackData): array
    {
        return [
            'update_id' => time(),
            'callback_query' => [
                'id' => time(),
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'last_name' => 'Testov',
                    'username' => 'usertest',
                ],
                'message' => [
                    'message_id' => time(),
                    'from' => [
                        'id' => time(),
                        'is_bot' => true,
                        'first_name' => 'Prog-Time |Администратор сайта',
                        'username' => 'prog_time_bot',
                    ],
                    'chat' => [
                        'id' => $chatId,
                        'first_name' => 'Test',
                        'last_name' => 'Testov',
                        'username' => 'usertest',
                        'type' => 'private',
                    ],
                    'date' => time(),
                    'text' => 'Тестовое сообщение',
                ],
                'chat_instance' => (string)time(),
                'data' => $callbackData,
            ],
        ];
    }

    /**
     * @param array $dtoParams
     *
     * @return TelegramUpdateDto
     */
    public static function getDto(array $dtoParams = []): TelegramUpdateDto
    {
        $request = Request::create('api/telegram/bot', 'POST', $dtoParams);
        return TelegramUpdateDto::fromRequest($request);
    }
}
