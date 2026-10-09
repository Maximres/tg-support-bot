<?php

namespace App\Services\Backup;

use App\Models\BotSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Phar;
use PharData;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Резервное копирование БД: pg_dump -> gzip -> локальная копия и зашифрованный
 * архив (дамп + .env), отправляемый в Telegram-чат — копия вне сервера.
 *
 * Шифрование совместимо с `openssl enc -d -aes-256-cbc -pbkdf2 -pass ...`,
 * поэтому копию можно расшифровать на любой машине (см. scripts/restore-db.sh).
 */
class DatabaseBackupService
{
    public const KEY_ENABLED = 'backup.enabled';

    public const KEY_TIME = 'backup.time';

    public const KEY_DAYS = 'backup.days';

    public const KEY_CHAT_ID = 'backup.chat_id';

    public const KEY_LAST_RUN_DATE = 'backup.last_run_date';

    public const KEY_LAST_AT = 'backup.last_at';

    public const KEY_LAST_OK = 'backup.last_ok';

    public const KEY_LAST_SIZE = 'backup.last_size';

    public const KEY_LAST_ERROR = 'backup.last_error';

    public const DEFAULT_TIME = '03:30';

    public const DEFAULT_DAYS = 1;

    public const MAX_DAYS = 30;

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return BotSetting::get(self::KEY_ENABLED) === '1';
    }

    /**
     * @param bool $enabled
     *
     * @return void
     */
    public function setEnabled(bool $enabled): void
    {
        BotSetting::set(self::KEY_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * Время ежедневного запуска (ЧЧ:ММ) в часовом поясе config('backup.timezone')
     *
     * @return string
     */
    public function time(): string
    {
        return BotSetting::get(self::KEY_TIME, self::DEFAULT_TIME);
    }

    /**
     * @param string $time
     *
     * @return void
     */
    public function setTime(string $time): void
    {
        BotSetting::set(self::KEY_TIME, $time);
    }

    /**
     * @param string $time
     *
     * @return bool
     */
    public static function isValidTime(string $time): bool
    {
        return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    /**
     * Как часто делать копию: раз в N дней
     *
     * @return int
     */
    public function days(): int
    {
        $days = (int)BotSetting::get(self::KEY_DAYS, (string)self::DEFAULT_DAYS);

        return $days >= 1 && $days <= self::MAX_DAYS ? $days : self::DEFAULT_DAYS;
    }

    /**
     * @param int $days
     *
     * @return void
     */
    public function setDays(int $days): void
    {
        BotSetting::set(self::KEY_DAYS, (string)$days);
    }

    /**
     * @param string $days
     *
     * @return bool
     */
    public static function isValidDays(string $days): bool
    {
        return ctype_digit($days) && (int)$days >= 1 && (int)$days <= self::MAX_DAYS;
    }

    /**
     * Чат, куда уходят копии: заданный администратором в Telegram, иначе из настроек сервера
     *
     * @return string|null
     */
    public function chatId(): ?string
    {
        $chatId = BotSetting::get(self::KEY_CHAT_ID);

        if (!empty($chatId)) {
            return (string)$chatId;
        }

        $fromConfig = config('backup.chat_id');

        return empty($fromConfig) ? null : (string)$fromConfig;
    }

    /**
     * @param string $chatId
     *
     * @return void
     */
    public function setChatId(string $chatId): void
    {
        BotSetting::set(self::KEY_CHAT_ID, $chatId);
    }

    /**
     * Id пользователя или группы: целое число, не ноль
     *
     * @param string $chatId
     *
     * @return bool
     */
    public static function isValidChatId(string $chatId): bool
    {
        return (bool)preg_match('/^-?[1-9]\d{4,18}$/', $chatId);
    }

    /**
     * Не хватает настроек сервера, без которых копия не уйдёт за пределы сервера
     *
     * @return string|null текст проблемы или null, если всё настроено
     */
    public function configurationProblem(): ?string
    {
        if (empty($this->chatId())) {
            return __('messages.backup.no_recipient');
        }

        if (empty(config('backup.passphrase'))) {
            return __('messages.backup.not_configured');
        }

        return null;
    }

    /**
     * @return array{enabled: bool, time: string, days: int, chat_id: ?string, timezone: string, last_at: ?string, last_ok: ?bool, last_size: ?int, last_error: ?string, local_copies: int}
     */
    public function status(): array
    {
        $lastAt = BotSetting::get(self::KEY_LAST_AT);
        $lastOk = BotSetting::get(self::KEY_LAST_OK);
        $dir = config('backup.dir');

        return [
            'enabled' => $this->isEnabled(),
            'time' => $this->time(),
            'days' => $this->days(),
            'chat_id' => $this->chatId(),
            'timezone' => config('backup.timezone'),
            'last_at' => $lastAt ? Carbon::parse($lastAt, 'UTC')->setTimezone(config('backup.timezone'))->format('d.m.Y H:i') : null,
            'last_ok' => $lastOk === null ? null : $lastOk === '1',
            'last_size' => BotSetting::get(self::KEY_LAST_SIZE) !== null ? (int)BotSetting::get(self::KEY_LAST_SIZE) : null,
            'last_error' => BotSetting::get(self::KEY_LAST_ERROR),
            'local_copies' => is_dir($dir) ? count(glob($dir . '/db-*.sql.gz') ?: []) : 0,
        ];
    }

    /**
     * Вызывается планировщиком каждую минуту: запускает бэкап, когда наступил заданный день
     * (раз в N дней) и время (если сервер был выключен — при первом запуске после)
     *
     * @return void
     */
    public function runIfDue(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $now = now(config('backup.timezone'));

        if ($now->format('H:i') < $this->time()) {
            return;
        }

        $lastRunDate = BotSetting::get(self::KEY_LAST_RUN_DATE);

        if ($lastRunDate === $now->toDateString()) {
            return;
        }

        if (!empty($lastRunDate)) {
            $daysSinceLastRun = (int)Carbon::parse($lastRunDate, config('backup.timezone'))->startOfDay()
                ->diffInDays($now->copy()->startOfDay(), true);

            if ($daysSinceLastRun < $this->days()) {
                return;
            }
        }

        // Отмечаем запуск заранее, чтобы при сбое не повторять каждую минуту
        BotSetting::set(self::KEY_LAST_RUN_DATE, $now->toDateString());

        $this->run();
    }

    /**
     * Выполнить бэкап прямо сейчас
     *
     * @return array{ok: bool, size?: int, error?: string}
     */
    public function run(): array
    {
        $dir = config('backup.dir');
        File::ensureDirectoryExists($dir, 0700);

        $stamp = now('UTC')->format('Ymd-His');
        $dumpPath = $dir . '/db-' . $stamp . '.sql.gz';

        try {
            if ($problem = $this->configurationProblem()) {
                throw new RuntimeException($problem);
            }

            $this->dump($dumpPath);
            $this->assertValidDump($dumpPath);
            @chmod($dumpPath, 0600);

            $size = (int)filesize($dumpPath);

            $this->sendOffsite($dumpPath, $stamp);
            $this->prune($dir);

            $this->recordResult(true, $size, null);

            return ['ok' => true, 'size' => $size];
        } catch (Throwable $e) {
            @unlink($dumpPath);
            $this->recordResult(false, null, $e->getMessage());

            Log::error('DatabaseBackupService: бэкап не удался', ['error' => $e->getMessage()]);
            $this->notify(__('messages.backup.failed', ['error' => $e->getMessage()]));

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Дамп БД в gzip-файл. Вынесено в отдельный метод, чтобы подменять в тестах
     *
     * @param string $path
     *
     * @return void
     */
    protected function dump(string $path): void
    {
        $db = config('database.connections.pgsql');

        $process = new Process(
            ['pg_dump', '--no-owner', '-h', (string)$db['host'], '-p', (string)$db['port'], '-U', (string)$db['username'], (string)$db['database']],
            null,
            ['PGPASSWORD' => (string)$db['password']]
        );
        $process->setTimeout(600);

        $gz = gzopen($path, 'wb9');
        if ($gz === false) {
            throw new RuntimeException('Не удалось создать файл дампа');
        }

        try {
            $process->run(function ($type, $buffer) use ($gz) {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);
                }
            });
        } finally {
            gzclose($gz);
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException('pg_dump: ' . trim($process->getErrorOutput() ?: 'код ' . $process->getExitCode()));
        }
    }

    /**
     * @param string $path
     *
     * @return void
     */
    private function assertValidDump(string $path): void
    {
        if (!is_file($path) || filesize($path) < 1024) {
            throw new RuntimeException('Дамп БД пустой или слишком маленький');
        }

        // Читаем архив до конца: ловим повреждённый gzip
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new RuntimeException('Дамп БД не читается как gzip');
        }
        while (!gzeof($gz)) {
            if (gzread($gz, 1 << 20) === false) {
                gzclose($gz);
                throw new RuntimeException('Дамп БД повреждён');
            }
        }
        gzclose($gz);
    }

    /**
     * Упаковать дамп и .env в архив, зашифровать и отправить в Telegram-чат
     *
     * @param string $dumpPath
     * @param string $stamp
     *
     * @return void
     */
    private function sendOffsite(string $dumpPath, string $stamp): void
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'bkp');
        $tar = $tmpBase . '.tar';
        $tarGz = $tar . '.gz';

        try {
            $phar = new PharData($tar);
            $phar->addFile($dumpPath, 'db.sql.gz');
            if (is_file(base_path('.env'))) {
                $phar->addFile(base_path('.env'), 'env');
            }
            $phar->compress(Phar::GZ);

            $encrypted = self::encrypt((string)file_get_contents($tarGz), (string)config('backup.passphrase'));
        } finally {
            foreach ([$tmpBase, $tar, $tarGz] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        $size = strlen($encrypted);
        if ($size > config('backup.max_upload_bytes')) {
            throw new RuntimeException("Копия ({$size} байт) слишком велика для отправки в Telegram. Локальная копия: " . basename($dumpPath));
        }

        $request = Http::timeout(120)->attach('document', $encrypted, "backup-{$stamp}.tar.gz.enc");

        if (config('traffic_source.settings.telegram.force_ipv4')) {
            $request = $request->withOptions(['force_ip_resolve' => 'v4']);
        }

        $response = $request->post('https://api.telegram.org/bot' . config('traffic_source.settings.telegram.token') . '/sendDocument', [
            'chat_id' => $this->chatId(),
            'caption' => __('messages.backup.caption', ['stamp' => $stamp, 'size' => intdiv($size, 1024)]),
        ]);

        if (!$response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram не принял копию: ' . ($response->json('description') ?? 'HTTP ' . $response->status()));
        }
    }

    /**
     * Удалить локальные копии старше config('backup.keep_days')
     *
     * @param string $dir
     *
     * @return void
     */
    private function prune(string $dir): void
    {
        $threshold = now()->subDays(config('backup.keep_days'))->getTimestamp();

        foreach (glob($dir . '/db-*.sql.gz') ?: [] as $file) {
            if (filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }

    /**
     * @param bool        $ok
     * @param int|null    $size
     * @param string|null $error
     *
     * @return void
     */
    private function recordResult(bool $ok, ?int $size, ?string $error): void
    {
        BotSetting::set(self::KEY_LAST_AT, now('UTC')->toDateTimeString());
        BotSetting::set(self::KEY_LAST_OK, $ok ? '1' : '0');
        BotSetting::set(self::KEY_LAST_SIZE, $size !== null ? (string)$size : null);
        BotSetting::set(self::KEY_LAST_ERROR, $error);
    }

    /**
     * Сообщение в чат бэкапов (например, о сбое)
     *
     * @param string $text
     *
     * @return void
     */
    private function notify(string $text): void
    {
        $chatId = $this->chatId();
        if (empty($chatId)) {
            return;
        }

        try {
            $request = Http::timeout(30);

            if (config('traffic_source.settings.telegram.force_ipv4')) {
                $request = $request->withOptions(['force_ip_resolve' => 'v4']);
            }

            $request->post('https://api.telegram.org/bot' . config('traffic_source.settings.telegram.token') . '/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (Throwable $e) {
            Log::warning('DatabaseBackupService: не удалось отправить уведомление', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Шифрование в формате `openssl enc -aes-256-cbc -pbkdf2` (соль, PBKDF2-SHA256, 10000 итераций)
     *
     * @param string $plain
     * @param string $passphrase
     *
     * @return string
     */
    public static function encrypt(string $plain, string $passphrase): string
    {
        $salt = random_bytes(8);
        $keyIv = hash_pbkdf2('sha256', $passphrase, $salt, 10000, 48, true);

        $cipher = openssl_encrypt($plain, 'aes-256-cbc', substr($keyIv, 0, 32), OPENSSL_RAW_DATA, substr($keyIv, 32, 16));

        if ($cipher === false) {
            throw new RuntimeException('Не удалось зашифровать копию');
        }

        return 'Salted__' . $salt . $cipher;
    }

    /**
     * Обратная операция к encrypt() — для проверки и тестов
     *
     * @param string $encrypted
     * @param string $passphrase
     *
     * @return string|false
     */
    public static function decrypt(string $encrypted, string $passphrase): string|false
    {
        if (!str_starts_with($encrypted, 'Salted__')) {
            return false;
        }

        $salt = substr($encrypted, 8, 8);
        $keyIv = hash_pbkdf2('sha256', $passphrase, $salt, 10000, 48, true);

        return openssl_decrypt(substr($encrypted, 16), 'aes-256-cbc', substr($keyIv, 0, 32), OPENSSL_RAW_DATA, substr($keyIv, 32, 16));
    }
}
