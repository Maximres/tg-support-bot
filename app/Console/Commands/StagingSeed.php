<?php

namespace App\Console\Commands;

use App\Enums\SafeCodeType;
use App\Models\SafeCode;
use App\Services\Rental\OfferDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Тестовый стенд: наполняет базу данными той же формы, что и боевая (правила, коды, договор),
 * чтобы меню и сценарии на стенде выглядели как на проде. На проде не запускается.
 */
class StagingSeed extends Command
{
    protected $signature = 'staging:seed
                            {--offer-pdf= : Путь к PDF договора: загружается тестовым ботом в тестовую группу, file_id сохраняется}
                            {--force : Перезаписать уже заданные значения}';

    protected $description = 'Тестовый стенд: заполняет правила, коды и договор тестовыми значениями';

    /**
     * @return int
     */
    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('staging:seed не предназначена для боевого окружения.');

            return Command::FAILURE;
        }

        $values = [
            [SafeCodeType::ORG_LINK, 'https://example.com/rules'],
            [SafeCodeType::SAFE, '1111'],
            [SafeCodeType::BUILDING, '2222'],
        ];

        foreach ($values as [$type, $value]) {
            if (!$this->option('force') && SafeCode::current($type)) {
                $this->line("{$type->value}: уже задано, пропускаю");
                continue;
            }

            SafeCode::create(['code' => $value, 'type' => $type->value]);
            $this->info("{$type->value}: задано");
        }

        if ($pdf = $this->option('offer-pdf')) {
            return $this->seedOffer((string)$pdf);
        }

        return Command::SUCCESS;
    }

    /**
     * @param string $path
     *
     * @return int
     */
    private function seedOffer(string $path): int
    {
        if (!is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return Command::FAILURE;
        }

        if (!$this->option('force') && !empty(OfferDocument::fileId())) {
            $this->line('договор: уже задан, пропускаю');

            return Command::SUCCESS;
        }

        $token = (string)config('traffic_source.settings.telegram.token');

        $response = Http::timeout(60)
            ->attach('document', (string)file_get_contents($path), basename($path))
            ->post("https://api.telegram.org/bot{$token}/sendDocument", [
                'chat_id' => config('traffic_source.settings.telegram.group_id'),
                'caption' => '🧪 Договор для тестового стенда',
            ]);

        $fileId = $response->json('result.document.file_id');

        if (!$response->successful() || empty($fileId)) {
            $this->error('Не удалось загрузить договор: ' . ($response->json('description') ?? 'HTTP ' . $response->status()));

            return Command::FAILURE;
        }

        OfferDocument::set($fileId, basename($path));
        $this->info('договор: загружен и сохранён');

        return Command::SUCCESS;
    }
}
