<?php

return [
    'settings' => [
        'telegram' => [
            'token' => env('TELEGRAM_TOKEN', ''),
            'secret_key' => env('TELEGRAM_SECRET_KEY', ''),
            'group_id' => env('TELEGRAM_GROUP_ID', ''),
            // use IPv4 only to connect to Telegram api
            'force_ipv4' => (bool)env('TELEGRAM_FORCE_IPV4', false),
            'template_topic_name' => env('TELEGRAM_TOPIC_NAME', ''),
            'broadcast_topic_id' => env('TELEGRAM_BROADCAST_TOPIC_ID', null),
            // Пересылка сообщений клиентов в тему «как есть» (видно имя и фото профиля клиента). По умолчанию выключена:
            // сообщения копируются в тему, как раньше. Включается TELEGRAM_FORWARD_CLIENT_MESSAGES=true
            'forward_client_messages' => (bool)env('TELEGRAM_FORWARD_CLIENT_MESSAGES', false),
            // Сколько секунд ждать остальные части альбома в рассылке
            'broadcast_album_wait' => (int)env('TELEGRAM_BROADCAST_ALBUM_WAIT', 4),
        ],
        'telegram_ai' => [
            'username' => env('TELEGRAM_AI_BOT_USERNAME', ''),
            'token' => env('TELEGRAM_AI_BOT_TOKEN', ''),
        ],

        'vk' => [
            'token' => env('VK_TOKEN', ''),
            'secret_key' => env('VK_SECRET_CODE', ''),
            'confirm_code' => env('VK_CONFIRM_CODE', ''),
        ],
    ],
];
