<?php

namespace App\Actions\Telegram;

use App\DTOs\TGTextMessageDto;
use App\Enums\SafeCodeType;
use App\Jobs\SendAccessMessageWithCallbackJob;
use App\Models\BotUser;
use App\Services\Rental\OfferDocument;

/**
 * Одноразовая отправка и закрепление в личном чате сотрудника меню материалов:
 * ссылки на инструкции, коды доступа, орг. информацию и договор
 */
class SendAccessMessage
{
    /**
     * @param BotUser $botUser
     * @param bool    $force Отправить заново, даже если сообщение уже отправлялось ранее
     *                       (например, сотрудник случайно удалил закреплённое сообщение)
     *
     * @return void
     */
    public function execute(BotUser $botUser, bool $force = false): void
    {
        if ($botUser->platform !== 'telegram' || empty($botUser->chat_id)) {
            return;
        }

        if ($botUser->isBanned() || (!$force && $botUser->hasAccessMessage())) {
            return;
        }

        $phone = config('rental.emergency_phone');

        $queryParams = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => __('messages.access_message_text', [
                'phone_line' => $phone ? __('messages.access_phone_line', ['phone' => $phone]) : '',
            ]),
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => $this->getKeyboard(),
            ],
        ]);

        SendAccessMessageWithCallbackJob::dispatch($botUser->id, $queryParams, $force);
    }

    /**
     * Кнопки меню. Ссылочные пункты без заданного в конфиге URL пропускаются.
     * Первая кнопка (общая страница инструкций) — на всю ширину, остальные — по две в ряд.
     *
     * @return array
     */
    public function getKeyboard(): array
    {
        $links = config('rental.links', []);

        $buttons = [];

        // График и ключи — только для доверенных: у них нет url-кнопки, ссылка выдаётся по нажатию
        foreach (['cabinets', 'map', 'schedule', 'payment', 'wifi'] as $key) {
            if (empty($links[$key])) {
                continue;
            }

            $buttons[] = $key === 'schedule'
                ? ['text' => __('messages.but_menu_schedule'), 'callback_data' => 'access_show_schedule']
                : ['text' => __("messages.but_menu_{$key}"), 'url' => $links[$key]];
        }

        $buttons[] = [
            'text' => SafeCodeType::ORG_LINK->buttonLabel(),
            'callback_data' => SafeCodeType::ORG_LINK->callbackData(),
        ];

        if (!empty($links['keys'])) {
            $buttons[] = ['text' => __('messages.but_menu_keys'), 'callback_data' => 'access_show_keys'];
        }

        $buttons[] = [
            'text' => SafeCodeType::SAFE->buttonLabel(),
            'callback_data' => SafeCodeType::SAFE->callbackData(),
        ];
        $buttons[] = [
            'text' => SafeCodeType::BUILDING->buttonLabel(),
            'callback_data' => SafeCodeType::BUILDING->callbackData(),
        ];

        if (!empty(OfferDocument::fileId())) {
            $buttons[] = ['text' => __('messages.but_menu_offer'), 'callback_data' => 'offer_show'];
        }

        $keyboard = [];

        if (!empty($links['hub'])) {
            $keyboard[] = [['text' => __('messages.but_menu_hub'), 'url' => $links['hub']]];
        }

        return array_merge($keyboard, array_chunk($buttons, 2));
    }
}
