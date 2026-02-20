<?php

namespace App\Http\Controllers;

use App\Actions\Ai\EditAiMessage;
use App\Actions\Telegram\BannedContactMessage;
use App\Actions\Telegram\CloseTopic;
use App\Actions\Telegram\EditUserData;
use App\Actions\Telegram\RenameTopic;
use App\Actions\Telegram\RequestPhoneFromGroup;
use App\Actions\Telegram\RestoreTopicName;
use App\Actions\Telegram\SendAiAnswerMessage;
use App\Actions\Telegram\SendBannedMessage;
use App\Actions\Telegram\SendContactMessage;
use App\Actions\Telegram\SendPhoneRequestMessage;
use App\Actions\Telegram\SendStartMessage;
use App\Actions\Telegram\ShowUserDataMenu;
use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Models\BotUser;
use App\Services\Tg\TgEditMessageService;
use App\Services\Tg\TgMessageService;
use App\Services\TgExternal\TgExternalEditService;
use App\Services\TgExternal\TgExternalMessageService;
use App\Services\TgVk\TgVkEditService;
use App\Services\TgVk\TgVkMessageService;
use App\Services\Broadcast\BroadcastMessageService;
use App\Actions\Telegram\IsBroadcastTopic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramBotController
{
    private TelegramUpdateDto $dataHook;

    protected ?string $platform;

    private ?BotUser $botUser;

    public function __construct(Request $request)
    {
        $dataHook = TelegramUpdateDto::fromRequest($request);
        $this->dataHook = !empty($dataHook) ? $dataHook : die();

        // Логируем входящий запрос для отладки broadcast
        if ($this->dataHook->typeQuery === 'message' && $this->dataHook->typeSource === 'supergroup') {
            Log::info('TelegramBotController: входящее сообщение в supergroup', [
                'message_thread_id' => $this->dataHook->messageThreadId,
                'type_query' => $this->dataHook->typeQuery,
                'type_source' => $this->dataHook->typeSource,
                'is_bot' => $this->dataHook->isBot,
                'update_id' => $this->dataHook->updateId,
            ]);
        }

        if ($this->dataHook->typeSource === 'private') {
            $this->botUser = (new BotUser())->getUserByChatId($this->dataHook->chatId, 'telegram');
            $this->platform = 'telegram';
        } else {
            // Проверяем, не является ли это топиком массовых рассылок
            // Если да, то не ищем пользователя и не прерываем выполнение
            if (IsBroadcastTopic::execute($this->dataHook->messageThreadId)) {
                // Это топик массовых рассылок - устанавливаем platform как 'telegram' для продолжения
                $this->platform = 'telegram';
                $this->botUser = null; // Для массовой рассылки пользователь не нужен
            } else {
                // Обычный топик - ищем пользователя
                $this->botUser = (new BotUser())->getByTopicId($this->dataHook->messageThreadId);
                $this->platform = $this->botUser->platform ?? null;
            }
        }

        if (empty($this->platform)) {
            die();
        }
    }

    /**
     * Check type source
     *
     * @return bool
     */
    protected function isSupergroup(): bool
    {
        return $this->dataHook->typeSource === 'supergroup';
    }

    /**
     * Проверить, соответствует ли текст команде (с учетом формата /command@bot_username)
     *
     * @param string $command Команда для проверки (например, '/contact')
     * @param string|null $text Текст сообщения
     *
     * @return bool
     */
    protected function isCommand(string $command, ?string $text): bool
    {
        if (empty($text)) {
            return false;
        }

        // Проверяем точное совпадение
        if ($text === $command) {
            return true;
        }

        // Проверяем формат /command@bot_username (любой бот) - точное совпадение
        if (preg_match('/^' . preg_quote($command, '/') . '@\w+$/', $text)) {
            return true;
        }

        // Проверяем начало строки для команд с параметрами
        // Формат: /command параметры или /command@bot параметры
        if (preg_match('/^' . preg_quote($command, '/') . '(?:@\w+)?(?:\s|$)/', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Check message
     *
     * @return void
     */
    protected function checkBotQuery(): void
    {
        if ($this->dataHook->pinnedMessageStatus) {
            die();
        }

        if ($this->dataHook->typeQuery === 'callback_query') {
            if (str_contains($this->dataHook->callbackData, 'topic_user_ban_')) {
                if ($this->botUser) {
                    $banStatus = $this->dataHook->callbackData === 'topic_user_ban_true';
                    (new BannedContactMessage())->execute($this->botUser, $banStatus, $this->dataHook->messageId);
                }
            } elseif ($this->dataHook->callbackData === 'close_topic') {
                if ($this->botUser) {
                    (new CloseTopic())->execute($this->botUser);
                }
            } elseif ($this->dataHook->callbackData === 'request_phone_from_group') {
                if ($this->botUser) {
                    (new RequestPhoneFromGroup())->execute($this->botUser);
                }
            } elseif ($this->dataHook->callbackData === 'edit_full_name') {
                if ($this->botUser && $this->dataHook->typeSource === 'private') {
                    (new EditUserData())->execute($this->dataHook, $this->botUser, 'full_name');
                }
            } elseif ($this->dataHook->callbackData === 'edit_phone') {
                if ($this->botUser && $this->dataHook->typeSource === 'private') {
                    (new EditUserData())->execute($this->dataHook, $this->botUser, 'phone');
                }
            } elseif ($this->dataHook->callbackData === 'edit_email') {
                if ($this->botUser && $this->dataHook->typeSource === 'private') {
                    (new EditUserData())->execute($this->dataHook, $this->botUser, 'email');
                }
            } elseif ($this->dataHook->callbackData === 'cancel_edit') {
                if ($this->botUser && $this->dataHook->typeSource === 'private') {
                    (new EditUserData())->cancel($this->dataHook, $this->botUser);
                }
            }

            die();
        }
    }

    /**
     * @return void
     *
     * @throws \Exception
     */
    public function bot_query(): void
    {
        $this->checkBotQuery();
        
        // Проверка на топик массовых рассылок (до определения платформы)
        // Логируем ВСЕ сообщения в supergroup для отладки
        if ($this->dataHook->typeQuery === 'message' && $this->dataHook->typeSource === 'supergroup') {
            Log::info('TelegramBotController: сообщение в supergroup - проверка на broadcast', [
                'message_thread_id' => $this->dataHook->messageThreadId,
                'type_query' => $this->dataHook->typeQuery,
                'type_source' => $this->dataHook->typeSource,
                'is_bot' => $this->dataHook->isBot,
                'update_id' => $this->dataHook->updateId,
                'message_id' => $this->dataHook->messageId,
            ]);
            
            if (!$this->dataHook->isBot) {
                // Получаем конфиг для логирования
                $broadcastTopicId = config('traffic_source.settings.telegram.broadcast_topic_id');
                Log::info('TelegramBotController: проверка IsBroadcastTopic', [
                    'message_thread_id' => $this->dataHook->messageThreadId,
                    'broadcast_topic_id_from_config' => $broadcastTopicId,
                    'broadcast_topic_id_type' => gettype($broadcastTopicId),
                ]);
                
                if (IsBroadcastTopic::execute($this->dataHook->messageThreadId)) {
                    // Это сообщение из топика массовых рассылок
                    Log::info('TelegramBotController: ✅ ЗАПУСК МАССОВОЙ РАССЫЛКИ', [
                        'message_thread_id' => $this->dataHook->messageThreadId,
                        'update_id' => $this->dataHook->updateId,
                        'message_id' => $this->dataHook->messageId,
                    ]);
                    (new BroadcastMessageService())->handle($this->dataHook);
                    echo 'ok';
                    return;
                } else {
                    Log::info('TelegramBotController: не является топиком массовых рассылок', [
                        'message_thread_id' => $this->dataHook->messageThreadId,
                    ]);
                }
            }
        }
        
        if ($this->dataHook->editedTopicStatus && $this->dataHook->typeSource === 'supergroup') {
            // Сохраняем кастомное название топика при ручном редактировании пользователем
            // НЕ сохраняем, если это сообщение от бота (бот сам обновил название через RestoreTopicName или RenameTopic)
            if ($this->botUser && 
                !$this->dataHook->isBot &&
                !empty($this->dataHook->rawData['message']['forum_topic_edited']) &&
                !empty($this->dataHook->rawData['message']['forum_topic_edited']['name'])) {
                try {
                    $newTopicName = $this->dataHook->rawData['message']['forum_topic_edited']['name'];
                    // Сохраняем как кастомное, так как это ручное редактирование пользователем
                    $this->botUser->setCustomTopicName($newTopicName);
                } catch (\Throwable $e) {
                    // Логируем ошибку, но продолжаем выполнение
                    \Log::warning('Ошибка сохранения кастомного названия топика', [
                        'error' => $e->getMessage(),
                        'bot_user_id' => $this->botUser->id ?? null,
                    ]);
                }
            }

            // Удаляем сообщение о редактировании
            SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                'methodQuery' => 'deleteMessage',
                'chat_id' => config('traffic_source.settings.telegram.group_id'),
                'message_id' => $this->dataHook->messageId,
            ]));
        } elseif (!$this->dataHook->isBot) {
            if ($this->dataHook->typeSource === 'supergroup') {
                if ($this->isCommand('/contact', $this->dataHook->text) && $this->isSupergroup()) {
                    (new SendContactMessage())->execute($this->botUser);
                    die();
                } elseif (($this->isCommand('/request_phone', $this->dataHook->text) || $this->isCommand('/get_phone', $this->dataHook->text)) && $this->isSupergroup() && $this->botUser) {
                    (new RequestPhoneFromGroup())->execute($this->botUser);
                    die();
                }
            }

            switch ($this->platform) {
                case 'telegram':
                    $this->controllerPlatformTg();
                    break;

                case 'vk':
                    $this->controllerPlatformVk();
                    break;

                case 'ignore':
                    return;

                default:
                    $this->controllerExternalPlatform();
                    break;
            }
        }
    }

    /**
     * Controller tg message
     *
     * @return void
     */
    private function controllerPlatformTg(): void
    {
        // Проверяем что botUser существует
        if (!$this->botUser) {
            Log::warning('TelegramBotController: botUser is null', [
                'typeSource' => $this->dataHook->typeSource,
                'chatId' => $this->dataHook->chatId,
                'messageThreadId' => $this->dataHook->messageThreadId ?? null,
            ]);
            die();
        }
        
        if ($this->botUser->isBanned() && $this->dataHook->typeSource === 'private') {
            (new SendBannedMessage())->execute($this->botUser);
            die();
        } elseif ($this->dataHook->aiTechMessage) {
            if (str_contains($this->dataHook->text, 'ai_message_edit_')) {
                (new EditAiMessage())->execute($this->dataHook);
            }
        } else {
            switch ($this->dataHook->typeQuery) {
                case 'message':
                    if ($this->isCommand('/start', $this->dataHook->text) && !$this->isSupergroup()) {
                        (new SendStartMessage())->execute($this->dataHook);
                    } elseif (($this->isCommand('/phone', $this->dataHook->text) || $this->isCommand('/share_phone', $this->dataHook->text)) && !$this->isSupergroup()) {
                        (new SendPhoneRequestMessage())->execute($this->dataHook);
                    } elseif ($this->isCommand('/my_data', $this->dataHook->text) && !$this->isSupergroup()) {
                        (new ShowUserDataMenu())->execute($this->dataHook, $this->botUser);
                    } elseif ($this->isCommand('/edit_name', $this->dataHook->text) && !$this->isSupergroup()) {
                        (new EditUserData())->execute($this->dataHook, $this->botUser, 'full_name');
                    } elseif ($this->isCommand('/edit_phone', $this->dataHook->text) && !$this->isSupergroup()) {
                        (new EditUserData())->execute($this->dataHook, $this->botUser, 'phone');
                    } elseif ($this->isCommand('/edit_email', $this->dataHook->text) && !$this->isSupergroup()) {
                        (new EditUserData())->execute($this->dataHook, $this->botUser, 'email');
                    } elseif ($this->isCommand('/cancel', $this->dataHook->text) && !$this->isSupergroup()) {
                        (new EditUserData())->cancel($this->dataHook, $this->botUser);
                    } elseif ($this->dataHook->text && str_contains($this->dataHook->text, '/ai_generate') && $this->isSupergroup()) {
                        (new SendAiAnswerMessage())->execute($this->dataHook);
                    } elseif ($this->isCommand('/rename_topic', $this->dataHook->text) && $this->isSupergroup()) {
                        (new RenameTopic())->execute($this->dataHook);
                    } elseif ($this->isCommand('/restore_topic_name', $this->dataHook->text) && $this->isSupergroup()) {
                        (new RestoreTopicName())->execute($this->dataHook);
                    } else {
                        (new TgMessageService($this->dataHook))->handleUpdate();
                    }
                    break;

                case 'edited_message':
                    (new TgEditMessageService($this->dataHook))->handleUpdate();
                    break;

                default:
                    throw new \Exception("Неизвестный тип события: {$this->dataHook->typeQuery}");
            }
        }
    }

    /**
     * Controller vk message
     *
     * @return void
     */
    private function controllerPlatformVk(): void
    {
        switch ($this->dataHook->typeQuery) {
            case 'message':
                (new TgVkMessageService($this->dataHook))->handleUpdate();
                break;

            case 'edited_message':
                (new TgVkEditService($this->dataHook))->handleUpdate();
                break;

            default:
                throw new \Exception("Неизвестный тип события: {$this->dataHook->typeQuery}");
        }
    }

    /**
     * Controller external message
     *
     * @return void
     */
    private function controllerExternalPlatform(): void
    {
        switch ($this->dataHook->typeQuery) {
            case 'message':
                (new TgExternalMessageService($this->dataHook))->handleUpdate();
                break;

            case 'edited_message':
                (new TgExternalEditService($this->dataHook))->handleUpdate();
                break;

            default:
                throw new \Exception("Неизвестный тип события: {$this->dataHook->typeQuery}");
        }
    }
}
