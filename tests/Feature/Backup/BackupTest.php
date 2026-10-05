<?php

namespace Tests\Feature\Backup;

use App\Actions\Telegram\HandleBackupCommand;
use App\DTOs\TelegramUpdateDto;
use App\Models\BotSetting;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request as RequestFacade;
use PharData;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Stubs\Services\Backup\FakeDumpBackupService;
use Tests\TestCase;

/**
 * Бэкап БД, управляемый администраторами из Telegram
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $chatMemberStatus = 'administrator';

    private string $backupDir;

    private FakeDumpBackupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = sys_get_temp_dir() . '/tg-backup-test-' . uniqid();

        config([
            'backup.dir' => $this->backupDir,
            'backup.chat_id' => '555000',
            'backup.passphrase' => 'test-passphrase',
            'backup.timezone' => 'Europe/Minsk',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->chatMemberStatus]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });

        $this->service = new FakeDumpBackupService();
        $this->app->instance(DatabaseBackupService::class, $this->service);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);

        parent::tearDown();
    }

    private function groupDto(string $text): TelegramUpdateDto
    {
        $request = RequestFacade::create('api/telegram/bot', 'POST', [
            'update_id' => time(),
            'message' => [
                'message_id' => time(),
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Admin', 'username' => 'admin_user'],
                'chat' => ['id' => -1001234567890, 'title' => 'Test Group', 'is_forum' => true, 'type' => 'supergroup'],
                'date' => time(),
                'text' => $text,
            ],
        ]);

        return TelegramUpdateDto::fromRequest($request);
    }

    private function command(string $text): void
    {
        $name = explode(' ', $text)[0];
        (new HandleBackupCommand())->execute($this->groupDto($text), $name);
    }

    private function lastReplyText(): ?string
    {
        $texts = [];
        foreach (Http::recorded()->all() as [$request]) {
            if (str_contains($request->url(), 'sendMessage') && !str_starts_with((string)($request['chat_id'] ?? ''), '555')) {
                $texts[] = $request['text'];
            }
        }

        return end($texts) ?: null;
    }

    public function test_admin_can_enable_and_disable_backup(): void
    {
        $this->assertFalse($this->service->isEnabled());

        $this->command('/backup_on');
        $this->assertTrue($this->service->isEnabled());
        $this->assertStringContainsString('включён', $this->lastReplyText());

        $this->command('/backup_off');
        $this->assertFalse($this->service->isEnabled());
        $this->assertStringContainsString('выключен', $this->lastReplyText());
    }

    public function test_non_admin_cannot_manage_backup(): void
    {
        $this->chatMemberStatus = 'member';

        $this->command('/backup_on');
        $this->command('/backup_now');

        $this->assertFalse($this->service->isEnabled());
        $this->assertSame(0, $this->service->dumps);
        $this->assertStringContainsString('администратор', $this->lastReplyText());
    }

    public function test_enable_warns_when_server_is_not_configured(): void
    {
        config(['backup.passphrase' => null]);

        $this->command('/backup_on');

        $this->assertTrue($this->service->isEnabled());
        $this->assertStringContainsString('BACKUP_PASSPHRASE', $this->lastReplyText());
    }

    public function test_backup_time_is_validated_and_saved(): void
    {
        $this->command('/backup_time 25:99');
        $this->assertSame('03:30', $this->service->time());
        $this->assertStringContainsString('ЧЧ:ММ', $this->lastReplyText());

        $this->command('/backup_time');
        $this->assertSame('03:30', $this->service->time());

        $this->command('/backup_time 04:15');
        $this->assertSame('04:15', $this->service->time());
        $this->assertStringContainsString('04:15', $this->lastReplyText());
    }

    public function test_backup_now_sends_encrypted_bundle_to_backup_chat(): void
    {
        $this->command('/backup_now');

        $this->assertSame(1, $this->service->dumps);
        $this->assertSame('1', BotSetting::get(DatabaseBackupService::KEY_LAST_OK));
        $this->assertCount(1, glob($this->backupDir . '/db-*.sql.gz'));
        $this->assertStringContainsString('Бэкап готов', $this->lastReplyText());

        $uploaded = null;
        foreach (Http::recorded()->all() as [$request]) {
            if (str_contains($request->url(), 'sendDocument')) {
                $parts = collect($request->data());
                $this->assertSame('555000', $parts->firstWhere('name', 'chat_id')['contents']);
                $uploaded = $parts->firstWhere('name', 'document');
            }
        }

        $this->assertNotNull($uploaded, 'sendDocument не вызывался');
        $this->assertStringStartsWith('backup-', $uploaded['filename']);

        // Файл расшифровывается паролем и внутри лежит дамп
        $plain = DatabaseBackupService::decrypt($uploaded['contents'], 'test-passphrase');
        $this->assertNotFalse($plain);
        $this->assertFalse(DatabaseBackupService::decrypt($uploaded['contents'], 'wrong-passphrase'));

        $tarGz = sys_get_temp_dir() . '/' . uniqid('chk') . '.tar.gz';
        file_put_contents($tarGz, $plain);
        $phar = new PharData($tarGz);
        $this->assertTrue(isset($phar['db.sql.gz']));
        @unlink($tarGz);
    }

    public function test_failed_backup_is_reported_and_leaves_no_partial_file(): void
    {
        $this->service->fail = true;

        $this->command('/backup_now');

        $this->assertSame('0', BotSetting::get(DatabaseBackupService::KEY_LAST_OK));
        $this->assertStringContainsString('boom', BotSetting::get(DatabaseBackupService::KEY_LAST_ERROR));
        $this->assertEmpty(glob($this->backupDir . '/db-*.sql.gz'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && (string)$r['chat_id'] === '555000'
            && str_contains($r['text'], 'Бэкап не удался'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'sendDocument'));
    }

    public function test_backup_is_not_sent_without_passphrase(): void
    {
        config(['backup.passphrase' => null]);

        $result = $this->service->run();

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $this->service->dumps);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'sendDocument'));
    }

    public function test_scheduler_runs_once_per_day_only_when_enabled_and_due(): void
    {
        // Выключен — не запускается
        $this->service->setTime('00:00');
        $this->service->runIfDue();
        $this->assertSame(0, $this->service->dumps);

        // Включён и время (00:00) уже наступило — запускается один раз в сутки
        $this->service->setEnabled(true);
        $this->service->runIfDue();
        $this->service->runIfDue();
        $this->assertSame(1, $this->service->dumps);
    }

    public function test_scheduler_waits_until_configured_time(): void
    {
        $this->service->setEnabled(true);
        $this->service->setTime('23:59');

        $this->travelTo(now('Europe/Minsk')->setTime(12, 0));
        $this->service->runIfDue();
        $this->assertSame(0, $this->service->dumps);

        $this->travelTo(now('Europe/Minsk')->setTime(23, 59, 30));
        $this->service->runIfDue();
        $this->assertSame(1, $this->service->dumps);
    }

    public function test_status_reports_last_result(): void
    {
        $this->command('/backup_status');
        $this->assertStringContainsString('Ещё ни разу', $this->lastReplyText());

        $this->command('/backup_now');
        $this->command('/backup_status');
        $this->assertStringContainsString('успешно', $this->lastReplyText());
        $this->assertStringContainsString('Локальных копий на сервере: 1', $this->lastReplyText());
    }

    public function test_encryption_is_compatible_with_openssl_cli(): void
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null) {
            $this->markTestSkipped('openssl CLI недоступен');
        }

        $encrypted = DatabaseBackupService::encrypt('секретные данные', 'test-passphrase');

        $process = new Process([$openssl, 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-pass', 'pass:test-passphrase']);
        $process->setInput($encrypted);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('секретные данные', $process->getOutput());
    }
}
