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
        'wifi' => env('RENTAL_LINK_WIFI'),
        // Ссылка на страницу с ключами — только для доверенных, поэтому открывается через callback
        'keys' => env('RENTAL_LINK_KEYS'),
    ],
];
