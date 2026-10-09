<?php

namespace App\Console\Commands;

use App\Actions\Telegram\PinContactCard;
use App\Actions\Telegram\UpdateContactMessage;
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
        $resent = 0;

        BotUser::query()
            ->whereNotNull('topic_id')
            ->whereNotNull('contact_info_message_id')
            ->chunkById(20, function ($botUsers) use ($pinner, &$done, &$resent) {
                foreach ($botUsers as $botUser) {
                    if ($pinner->execute($botUser)) {
                        $done++;
                        continue;
                    }

                    // Прежней карточки нет (удалена или отправлена другим ботом): присылаем новую, она закрепится сама
                    (new UpdateContactMessage())->execute($botUser);
                    $resent++;
                }

                sleep(1);
            });

        $this->info("Закреплено: {$done}, карточка отправлена заново: {$resent}");

        return Command::SUCCESS;
    }
}
