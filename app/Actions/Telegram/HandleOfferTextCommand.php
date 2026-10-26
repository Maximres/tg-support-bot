<?php

namespace App\Actions\Telegram;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Jobs\SendTelegramSimpleQueryJob;
use App\Logging\LokiLogger;
use App\Services\Rental\OfferText;
use Illuminate\Support\Facades\Log;

/**
 * Текст договора (сообщение клиенту перед кнопкой «Принимаю условия») из рабочей группы:
 *  /set_offer_text <текст> — задать новый текст; форматирование Telegram (жирный, ссылки…) сохраняется;
 *  /set_offer_text default — вернуть текст по умолчанию;
 *  /show_offer_text — показать текст, который сейчас видят клиенты.
 */
class HandleOfferTextCommand
{
    public const COMMANDS = [
        '/set_offer_text',
        '/show_offer_text',
    ];

    /**
     * @param TelegramUpdateDto $update
     * @param string            $command
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

            $command === '/set_offer_text'
                ? $this->set($groupId, $update)
                : $this->show($groupId, $update);
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            Log::error('HandleOfferTextCommand: неожиданная ошибка', ['command' => $command, 'error' => $e->getMessage()]);
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
        $text = (string)$update->text;
        $entities = $update->entities ?? [];

        // Текст может прийти после команды (/set_offer_text …) или целиком — ответом на подсказку панели
        if (preg_match('/^\/set_offer_text(?:@\w+)?\s*/i', $text, $match)) {
            $prefixLength = EntitiesToHtml::length($match[0]);
            $text = substr($text, strlen($match[0]));

            $entities = array_values(array_filter(
                array_map(function ($entity) use ($prefixLength) {
                    $entity['offset'] = (int)($entity['offset'] ?? 0) - $prefixLength;

                    return $entity;
                }, $entities),
                fn ($entity) => $entity['offset'] >= 0
            ));
        }

        $text = rtrim($text);

        if ($text === '') {
            $this->reply($groupId, $update->messageThreadId, __('messages.offer_text.usage', [
                'max' => OfferText::MAX_LENGTH,
            ]));
            return;
        }

        if (mb_strtolower($text) === 'default') {
            OfferText::reset();
            $this->reply($groupId, $update->messageThreadId, __('messages.offer_text.reset'));
            return;
        }

        if (EntitiesToHtml::length($text) > OfferText::MAX_LENGTH) {
            $this->reply($groupId, $update->messageThreadId, __('messages.offer_text.too_long', ['max' => OfferText::MAX_LENGTH]));
            return;
        }

        $html = EntitiesToHtml::convert($text, $entities);
        OfferText::set($html);

        $this->reply($groupId, $update->messageThreadId, __('messages.offer_text.saved'));
        $this->reply($groupId, $update->messageThreadId, $html);
    }

    /**
     * @param int|string        $groupId
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    private function show(int|string $groupId, TelegramUpdateDto $update): void
    {
        $this->reply($groupId, $update->messageThreadId, __(OfferText::isCustom() ? 'messages.offer_text.shown_custom' : 'messages.offer_text.shown_default'));
        $this->reply($groupId, $update->messageThreadId, OfferText::html());
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
