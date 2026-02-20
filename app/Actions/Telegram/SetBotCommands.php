<?php

namespace App\Actions\Telegram;

use App\TelegramBot\TelegramMethods;
use Illuminate\Support\Facades\Log;

/**
 * Установка команд бота через Telegram API
 */
class SetBotCommands
{
    /**
     * Установить команды для клиентов (private chats)
     *
     * @return bool
     */
    public function setPrivateChatCommands(): bool
    {
        $commands = [
            [
                'command' => 'start',
                'description' => __('messages.command_start_description'),
            ],
            [
                'command' => 'phone',
                'description' => __('messages.command_phone_description'),
            ],
        ];

        $params = [
            'commands' => $commands,
            'scope' => [
                'type' => 'all_private_chats',
            ],
        ];

        return $this->executeSetCommands('private chats', $params);
    }

    /**
     * Установить команды для администраторов (group chats)
     *
     * @return bool
     */
    public function setGroupChatCommands(): bool
    {
        $commands = [
            [
                'command' => 'contact',
                'description' => __('messages.command_contact_description'),
            ],
            [
                'command' => 'request_phone',
                'description' => __('messages.command_request_phone_description'),
            ],
            [
                'command' => 'rename_topic',
                'description' => __('messages.command_rename_topic_description'),
            ],
            [
                'command' => 'restore_topic_name',
                'description' => __('messages.command_restore_topic_name_description'),
            ],
        ];

        $params = [
            'commands' => $commands,
            'scope' => [
                'type' => 'all_chat_administrators',
            ],
        ];

        return $this->executeSetCommands('group chats (administrators)', $params);
    }

    /**
     * Установить команды для обоих типов чатов
     *
     * @return array
     */
    public function setAllCommands(): array
    {
        return [
            'private' => $this->setPrivateChatCommands(),
            'group' => $this->setGroupChatCommands(),
        ];
    }

    /**
     * Выполнить установку команд через Telegram API
     *
     * @param string $scopeName
     * @param array $params
     *
     * @return bool
     */
    private function executeSetCommands(string $scopeName, array $params): bool
    {
        try {
            $result = TelegramMethods::sendQueryTelegram('setMyCommands', $params);

            if ($result->ok) {
                Log::info("Bot commands установлены для {$scopeName}", [
                    'scope' => $params['scope']['type'],
                    'commands_count' => count($params['commands']),
                ]);

                return true;
            } else {
                Log::error("Ошибка установки команд для {$scopeName}", [
                    'scope' => $params['scope']['type'],
                    'error' => $result->rawData ?? 'Unknown error',
                ]);

                return false;
            }
        } catch (\Throwable $e) {
            Log::error("Исключение при установке команд для {$scopeName}", [
                'scope' => $params['scope']['type'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Получить текущие установленные команды
     *
     * @param array|null $scope
     *
     * @return array|null
     */
    public function getCommands(?array $scope = null): ?array
    {
        try {
            $params = [];
            if ($scope !== null) {
                $params['scope'] = $scope;
            }

            $result = TelegramMethods::sendQueryTelegram('getMyCommands', $params);

            if ($result->ok && isset($result->rawData['result'])) {
                return $result->rawData['result'];
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('Ошибка получения команд', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

