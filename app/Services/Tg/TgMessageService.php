<?php

namespace App\Services\Tg;

use App\Actions\Telegram\ConversionMessageText;
use App\Actions\Telegram\HandleRegistrationFlow;
use App\Actions\Telegram\UpdateContactMessage;
use App\Actions\Telegram\UpdateTopicName;
use App\DTOs\TelegramUpdateDto;
use App\Jobs\SendMessage\SendTelegramMessageJob;
use App\Logging\LokiLogger;
use App\Services\ActionService\Send\FromTgMessageService;
use App\Services\Registration\UserRegistrationService;
use Illuminate\Support\Facades\Log;

class TgMessageService extends FromTgMessageService
{
    public function __construct(TelegramUpdateDto $update)
    {
        parent::__construct($update);
    }

    /**
     * @return void
     */
    public function handleUpdate(): void
    {
        try {
            if ($this->update->typeQuery !== 'message') {
                throw new \Exception("Неизвестный тип события: {$this->update->typeQuery}", 1);
            }

            // Логируем тип сообщения для отладки
            Log::info('TgMessageService::handleUpdate: обработка сообщения', [
                'bot_user_id' => $this->botUser->id ?? null,
                'chat_id' => $this->update->chatId ?? null,
                'type_source' => $this->update->typeSource ?? null,
                'has_contact' => !empty($this->update->rawData['message']['contact']),
                'has_text' => !empty($this->update->text),
                'has_photo' => !empty($this->update->rawData['message']['photo']),
            ]);

            // Edge case: проверка на регистрацию или редактирование
            // Обрабатываем только в приватных чатах (текстовые сообщения и контакты)
            if ($this->update->typeSource === 'private' && 
                (!empty($this->update->text) || !empty($this->update->rawData['message']['contact']))) {
                $registrationService = new UserRegistrationService();
                
                // Edge case: определение первого контакта - если пользователь нуждается в регистрации
                // и нет состояния, запускаем регистрацию (кроме команды /start, которая обрабатывается отдельно)
                if ($this->botUser->needsRegistration() && 
                    !$registrationService->getState($this->update->chatId) &&
                    !empty($this->update->text) &&
                    !str_starts_with($this->update->text, '/start')) {
                    // Запускаем регистрацию через SendStartMessage
                    (new \App\Actions\Telegram\SendStartMessage())->execute($this->update);
                    return;
                }
                
                // Проверяем, находится ли пользователь в процессе регистрации или редактирования
                if ($registrationService->isInRegistration($this->update->chatId) || 
                    $registrationService->isEditing($this->update->chatId)) {
                    
                    // Обрабатываем через HandleRegistrationFlow
                    $registrationHandler = new HandleRegistrationFlow();
                    $handled = $registrationHandler->execute($this->update, $this->botUser);
                    
                    if ($handled) {
                        // Сообщение обработано в контексте регистрации, не продолжаем обычную обработку
                        return;
                    }
                }
            }

            if (!empty($this->update->rawData['message']['photo'])) {
                $this->sendPhoto();
            } elseif (!empty($this->update->rawData['message']['document'])) {
                $this->sendDocument();
            } elseif (!empty($this->update->rawData['message']['location'])) {
                $this->sendLocation();
            } elseif (!empty($this->update->rawData['message']['voice'])) {
                $this->sendVoice();
            } elseif (!empty($this->update->rawData['message']['sticker'])) {
                $this->sendSticker();
            } elseif (!empty($this->update->rawData['message']['video_note'])) {
                $this->sendVideoNote();
            } elseif (!empty($this->update->rawData['message']['contact'])) {
                $this->sendContact();
            } elseif (!empty($this->update->text)) {
                $this->sendMessage();
            }

            SendTelegramMessageJob::dispatch(
                $this->botUser->id,
                $this->update,
                $this->messageParamsDTO,
                $this->typeMessage,
            );
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
        }
    }

    /**
     * @return void
     */
    protected function sendPhoto(): void
    {
        $this->messageParamsDTO->methodQuery = 'sendPhoto';
        $this->messageParamsDTO->photo = $this->update->fileId;

        $this->messageParamsDTO->caption = $this->update->caption;
        if (!empty($this->update->entities)) {
            $this->messageParamsDTO->caption = ConversionMessageText::conversionMarkdownFormat($this->update->caption, $this->update->entities);
            $this->messageParamsDTO->parse_mode = 'MarkdownV2';
        }
    }

    /**
     * @return void
     */
    protected function sendDocument(): void
    {
        $this->messageParamsDTO->methodQuery = 'sendDocument';
        $this->messageParamsDTO->document = $this->update->fileId;

        $this->messageParamsDTO->caption = $this->update->caption;
        if (!empty($this->update->entities)) {
            $this->messageParamsDTO->caption = ConversionMessageText::conversionMarkdownFormat($this->update->caption, $this->update->entities);
            $this->messageParamsDTO->parse_mode = 'MarkdownV2';
        }
    }

    /**
     * @return void
     */
    protected function sendLocation(): void
    {
        $this->messageParamsDTO->methodQuery = 'sendLocation';
        $this->messageParamsDTO->latitude = $this->update->location['latitude'];
        $this->messageParamsDTO->longitude = $this->update->location['longitude'];
    }

    /**
     * @return void
     */
    protected function sendVoice(): void
    {
        $this->messageParamsDTO->methodQuery = 'sendVoice';
        $this->messageParamsDTO->voice = $this->update->fileId;
    }

    /**
     * @return void
     */
    protected function sendSticker(): void
    {
        $this->messageParamsDTO->methodQuery = 'sendSticker';
        $this->messageParamsDTO->sticker = $this->update->fileId;
    }

    /**
     * @return void
     */
    protected function sendVideoNote(): void
    {
        $this->messageParamsDTO->methodQuery = 'sendVideoNote';
        $this->messageParamsDTO->video_note = $this->update->fileId;
    }

    /**
     * @return void
     */
    protected function sendContact(): void
    {
        Log::info('TgMessageService::sendContact: начало обработки контакта', [
            'bot_user_id' => $this->botUser->id ?? null,
            'chat_id' => $this->update->chatId ?? null,
            'type_source' => $this->update->typeSource ?? null,
            'has_contact' => !empty($this->update->rawData['message']['contact']),
        ]);
        
        $this->messageParamsDTO->methodQuery = 'sendMessage';
        $contactData = $this->update->rawData['message']['contact'] ?? [];

        Log::info('TgMessageService::sendContact: данные контакта', [
            'bot_user_id' => $this->botUser->id ?? null,
            'contact_data' => $contactData,
            'phone_number' => $contactData['phone_number'] ?? null,
        ]);

        // Сохраняем номер телефона в BotUser
        if (!empty($contactData['phone_number']) && $this->botUser) {
            $oldPhoneNumber = $this->botUser->phone_number;
            $this->botUser->phone_number = $contactData['phone_number'];
            $this->botUser->save();

            // Обновляем название топика, если номер был сохранен или изменен
            try {
                // Обновляем модель из БД для получения актуальных данных
                $this->botUser->refresh();
                
                // Логируем информацию для отладки
                Log::info('TgMessageService::sendContact: номер телефона сохранен, обновляем название топика', [
                    'bot_user_id' => $this->botUser->id ?? null,
                    'topic_id' => $this->botUser->topic_id ?? null,
                    'phone_number' => $this->botUser->phone_number,
                    'old_phone_number' => $oldPhoneNumber,
                    'has_custom_topic_name' => $this->botUser->hasCustomTopicName(),
                    'custom_topic_name' => $this->botUser->getCustomTopicName(),
                ]);
                
                (new UpdateTopicName())->execute($this->botUser);
                
                // Обновляем контактное сообщение в топике
                (new UpdateContactMessage())->execute($this->botUser);
            } catch (\Throwable $e) {
                // Edge case: ошибки при обновлении топика не должны ломать обработку контакта
                Log::warning('TgMessageService::sendContact: ошибка при обновлении названия топика', [
                    'bot_user_id' => $this->botUser->id ?? null,
                    'topic_id' => $this->botUser->topic_id ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $textMessage = "Контакт: \n";
        $textMessage .= "Имя: {$contactData['first_name']}\n";
        if (!empty($contactData['phone_number'])) {
            $textMessage .= "Телефон: {$contactData['phone_number']}\n";
        }

        $this->messageParamsDTO->text = $textMessage;
    }

    /**
     * @return void
     */
    protected function sendMessage(): void
    {
        $this->messageParamsDTO->text = $this->update->text;
        if (!empty($this->update->entities)) {
            $this->messageParamsDTO->text = ConversionMessageText::conversionMarkdownFormat($this->update->text, $this->update->entities);
            $this->messageParamsDTO->parse_mode = 'MarkdownV2';
        }
    }
}
