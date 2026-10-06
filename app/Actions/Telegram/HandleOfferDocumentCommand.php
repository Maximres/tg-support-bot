<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Services\Rental\OfferDocument;
use Illuminate\Support\Facades\Log;

/**
 * Договор-оферта из рабочей группы:
 *  /set_offer  — администратор присылает PDF с подписью /set_offer (или отвечает этой командой на сообщение с PDF)
 *  /show_offer — прислать в тему текущий договор, чтобы убедиться, какой файл активен
 */
class HandleOfferDocumentCommand
{
    public const COMMANDS = [
        '/set_offer',
        '/show_offer',
    ];

    private const MAX_SIZE_BYTES = 20 * 1024 * 1024;

    /**
     * @param TelegramUpdateDto $update
     * @param string            $command одна из self::COMMANDS
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update, string $command): void
    {
        $groupId = config('traffic_source.settings.telegram.group_id');

        try {
            $isAdmin = VerifyGroupAdmin::check((int)$groupId, $update->fromUserId);
            if ($isAdmin !== true) {
                $this->reply($groupId, $update->messageThreadId, $isAdmin === null
                    ? __('messages.command_admin_check_failed')
                    : __('messages.command_admin_only'));
                return;
            }

            $command === '/set_offer'
                ? $this->set($groupId, $update)
                : $this->show($groupId, $update);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('HandleOfferDocumentCommand: неожиданная ошибка', [
                'command' => $command,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param int|string        $groupId
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    private function set(int|string $groupId, TelegramUpdateDto $update): void
    {
        // PDF либо прикреплён к самой команде (в подписи), либо команда — ответ на сообщение с PDF
        $document = $update->rawData['message']['document']
            ?? $update->rawData['message']['reply_to_message']['document']
            ?? null;

        if (empty($document['file_id'])) {
            $this->reply($groupId, $update->messageThreadId, __('messages.offer.set_usage'));
            return;
        }

        $fileName = (string)($document['file_name'] ?? '');
        $isPdf = ($document['mime_type'] ?? '') === 'application/pdf' || str_ends_with(mb_strtolower($fileName), '.pdf');

        if (!$isPdf) {
            $this->reply($groupId, $update->messageThreadId, __('messages.offer.set_not_pdf'));
            return;
        }

        if (($document['file_size'] ?? 0) > self::MAX_SIZE_BYTES) {
            $this->reply($groupId, $update->messageThreadId, __('messages.offer.set_too_big'));
            return;
        }

        OfferDocument::set($document['file_id'], $fileName !== '' ? $fileName : null);

        Log::info('HandleOfferDocumentCommand: договор-оферта заменён', [
            'set_by_user_id' => $update->fromUserId,
            'file_name' => $fileName,
        ]);

        $this->reply($groupId, $update->messageThreadId, __('messages.offer.set_saved', [
            'name' => $fileName !== '' ? $fileName : 'без названия',
        ]));
    }

    /**
     * @param int|string        $groupId
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    private function show(int|string $groupId, TelegramUpdateDto $update): void
    {
        $fileId = OfferDocument::fileId();

        if (empty($fileId)) {
            $this->reply($groupId, $update->messageThreadId, __('messages.offer.show_none'));
            return;
        }

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendDocument',
            'chat_id' => $groupId,
            'message_thread_id' => $update->messageThreadId,
            'document' => $fileId,
            'caption' => __('messages.offer.show_caption'),
        ]));
    }

    /**
     * @param int|string $groupId
     * @param int|null   $threadId
     * @param string     $text
     *
     * @return void
     */
    private function reply(int|string $groupId, ?int $threadId, string $text): void
    {
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $groupId,
            'message_thread_id' => $threadId,
            'text' => $text,
            'parse_mode' => 'html',
        ]));
    }
}
