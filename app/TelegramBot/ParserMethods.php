<?php

namespace App\TelegramBot;

use App\Logging\LokiLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use phpDocumentor\Reflection\Exception as phpDocumentorException;

class ParserMethods
{
    /**
     * Скрывает токен бота в тексте ошибки: cURL подставляет в сообщение полный URL запроса,
     * и токен попадал в логи
     *
     * @param string $message
     *
     * @return string
     */
    public static function maskBotToken(string $message): string
    {
        return preg_replace('~/bot\d+:[A-Za-z0-9_-]+/~', '/bot***/', $message) ?? $message;
    }

    /**
     * Send POST
     *
     * @param string       $urlQuery
     * @param array|string $queryParams
     * @param array        $queryHeading
     *
     * @return array
     */
    public static function postQuery(string $urlQuery, array|string $queryParams = [], array $queryHeading = []): array
    {
        try {
            // Обрыв соединения с Telegram (cURL 35/56 и т.п.) чаще всего разовый — повторяем,
            // иначе сообщение молча теряется (очередь синхронная, перезапускать некому)
            $resultQuery = Http::withHeaders($queryHeading)
                ->when(config('traffic_source.settings.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                ->retry(2, 300, fn ($exception) => $exception instanceof ConnectionException, throw: false)
                ->post($urlQuery, $queryParams)
                ->json();

            if (empty($resultQuery)) {
                throw new \RuntimeException('Запрос вызвал ошибку');
            }

            return $resultQuery;
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            return [
                'ok' => false,
                'response_code' => 500,
                'result' => self::maskBotToken($e->getMessage()),
            ];
        }
    }

    /**
     * Send GET
     *
     * @param string       $urlQuery
     * @param array|string $queryParams
     * @param array        $queryHeading
     *
     * @return array
     */
    public static function getQuery(string $urlQuery, array|string $queryParams = [], array $queryHeading = []): array
    {
        try {
            if (!empty($queryParams)) {
                $urlQuery .= '?' . http_build_query($queryParams);
            }

            $resultQuery = Http::withHeaders($queryHeading)
                ->when(config('traffic_source.settings.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                ->withoutVerifying()
                ->get($urlQuery)
                ->json();

            if (empty($resultQuery)) {
                throw new \RuntimeException('Запрос вызвал ошибку');
            }

            return $resultQuery;
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            return [
                'ok' => false,
                'response_code' => 500,
                'result' => self::maskBotToken($e->getMessage()),
            ];
        }
    }

    public static function attachQuery(string $urlQuery, array|string $queryParams = [], string $attachType = 'document'): array
    {
        try {
            if (empty($attachType)) {
                $attachType = 'document';
            }

            if (empty($queryParams['uploaded_file']) || !$queryParams['uploaded_file'] instanceof UploadedFile) {
                throw new phpDocumentorException('Файл не передан!');
            }

            /** @var UploadedFile $attachData */
            $attachData = $queryParams['uploaded_file'];
            unset($queryParams['uploaded_file']);

            // Проверка размера файла (макс. 50 МБ для Telegram)
            if ($attachData->getSize() > 50 * 1024 * 1024) {
                throw new phpDocumentorException('Файл слишком большой для Telegram (макс. 50 МБ)');
            }

            if ($attachData->getSize() === 0) {
                throw new phpDocumentorException('Файл пустой и не может быть отправлен');
            }

            // Проверка валидности файла
            if (!$attachData->isValid()) {
                throw new phpDocumentorException('Файл невалиден');
            }

            // Получение пути к временному файлу
            $tempPath = $attachData->getRealPath();

            if (!$tempPath || !file_exists($tempPath) || !is_readable($tempPath)) {
                throw new phpDocumentorException('Временный файл не существует или недоступен для чтения');
            }

            // Генерация уникального имени с UUID
            $extension = $attachData->getClientOriginalExtension();
            $safeName = Str::uuid() . ($extension ? '.' . $extension : '');

            // Отправка файла в Telegram
            $resultQuery = Http::attach(
                $attachType,
                fopen($tempPath, 'rb'),
                $safeName
            )
                ->when(config('traffic_source.settings.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                ->post($urlQuery, $queryParams)
                ->json();

            if (empty($resultQuery)) {
                throw new \RuntimeException('Запрос вызвал ошибку');
            }

            return $resultQuery;
        } catch (\Throwable $e) {
            (new LokiLogger())->logException($e);
            return [
                'ok' => false,
                'response_code' => 500,
                'result' => self::maskBotToken($e->getMessage()),
            ];
        }
    }
}
