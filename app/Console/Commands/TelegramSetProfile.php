<?php

namespace App\Console\Commands;

use App\TelegramBot\TelegramMethods;
use Illuminate\Console\Command;

/**
 * Оформление бота, которое видят клиенты в Telegram: описание (в пустом чате перед запуском), короткое описание
 * (в профиле бота и при пересылке) и, по желанию, название. Тексты — в resources/lang (bot_profile.*).
 */
class TelegramSetProfile extends Command
{
    protected $signature = 'telegram:set-profile
                            {--name : Также задать название бота (bot_profile.name)}';

    protected $description = 'Задаёт описание бота, которое видят клиенты (setMyDescription, setMyShortDescription)';

    /**
     * @return int
     */
    public function handle(): int
    {
        $calls = [
            ['setMyDescription', ['description' => __('messages.bot_profile.description')]],
            ['setMyShortDescription', ['short_description' => __('messages.bot_profile.short_description')]],
        ];

        if ($this->option('name')) {
            $calls[] = ['setMyName', ['name' => __('messages.bot_profile.name')]];
        }

        $failed = false;

        foreach ($calls as [$method, $params]) {
            $response = TelegramMethods::sendQueryTelegram($method, $params);

            if ($response->ok === true) {
                $this->info("✅ {$method}");
            } else {
                $failed = true;
                $this->error("❌ {$method}: " . ($response->rawData['description'] ?? 'ошибка'));
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
