<?php

/**
 * Материалы для арендаторов: договор-оферта, ссылки меню и контакты.
 * Значения задаются в .env — пустые пункты просто не показываются в меню.
 */
return [
    // URL или file_id PDF с договором-офертой
    'offer_document' => env('RENTAL_OFFER_DOCUMENT'),

    // Телефон для экстренной связи
    'emergency_phone' => env('RENTAL_EMERGENCY_PHONE'),

    'links' => [
        'hub' => env('RENTAL_LINK_HUB'),
        'cabinets' => env('RENTAL_LINK_CABINETS'),
        'map' => env('RENTAL_LINK_MAP'),
        'schedule' => env('RENTAL_LINK_SCHEDULE'),
        'payment' => env('RENTAL_LINK_PAYMENT'),
        // Ссылка на страницу с ключами — только для доверенных, поэтому открывается через callback
        'keys' => env('RENTAL_LINK_KEYS'),
        'prices' => env('RENTAL_LINK_PRICES'),
        'cancel' => env('RENTAL_LINK_CANCEL'),
        'groups' => env('RENTAL_LINK_GROUPS'),
    ],

    // Приложение Google Таблицы: в сообщении «График» подсказываем, где скачать его для телефона
    'apps' => [
        'sheets_android' => env('RENTAL_APP_SHEETS_ANDROID', 'https://play.google.com/store/apps/details?id=com.google.android.apps.docs.editors.sheets'),
        'sheets_ios' => env('RENTAL_APP_SHEETS_IOS', 'https://apps.apple.com/app/google-sheets/id842849113'),
    ],
];
