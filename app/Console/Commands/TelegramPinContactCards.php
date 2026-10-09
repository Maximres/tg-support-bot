<?php

namespace App\Console\Commands;

use App\Actions\Telegram\PinContactCard;
use App\Models\BotUser;
use Illuminate\Console\Command;

class TelegramPinContactCards extends Command
{
    protected $signature = 'telegram:pin-contact-cards';

    protected $description = 'Закрепляет контактные карточки всех клиентов в их темах';

    /**
     * @return int
     */
    public function handle(): int
    {
        $pinner = new PinContactCard();
        $done = 0;
        $failed = 0;

        BotUser::query()
            ->whereNotNull('topic_id')
            ->whereNotNull('contact_info_message_id')
            ->chunkById(20, function ($botUsers) use ($pinner, &$done, &$failed) {
                foreach ($botUsers as $botUser) {
                    $pinner->execute($botUser) ? $done++ : $failed++;
                }

                sleep(1);
            });

        $this->info("Закреплено: {$done}, не удалось: {$failed}");

        return Command::SUCCESS;
    }
}
