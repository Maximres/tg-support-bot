<?php

namespace App\Logging;

use Exception;
use App\TelegramBot\ParserMethods;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

class LokiLogger
{
    protected Client $client;

    protected string $url;

    public function __construct(Client|null $client = null)
    {
        $this->client = $client ?? new Client();
        $this->url = config('loki_custom.url') ?? '';
    }

    /**
     * @param Throwable $e
     *
     * @return void
     */
    public function sendBasicLog(Throwable $e): void
    {
        $errorMessageString = 'File: ' . $e->getFile() . '; ';
        $errorMessageString .= 'Line: ' . $e->getLine() . '; ';
        $errorMessageString .= 'Error: ' . $e->getMessage();

        $this->log('error', $errorMessageString);
    }

    /**
     * Log a message to the given channel.
     *
     * @param string $level
     * @param mixed  $message
     *
     * @return bool
     */
    public function log(string $level, mixed $message): bool
    {
        // Loki больше не используется, но этот класс вызывается из десятков мест, и раньше при
        // пустом URL всё молча терялось — теперь всегда пишем в обычный лог приложения
        $text = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE);
        Log::log($level, 'LokiLogger: ' . ParserMethods::maskBotToken((string)$text));

        try {
            // Если URL не настроен, во внешний сборщик не отправляем
            if (empty($this->url)) {
                return true;
            }

            $payload = [
                'streams' => [
                    [
                        'stream' => [
                            'app' => config('app.name'),
                            'env' => config('app.env'),
                            'level' => $level,
                        ],
                        'values' => [
                            [
                                (string) (int) (microtime(true) * 1e9),
                                is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE),
                            ],
                        ],
                    ],
                ],
            ];

            $this->client->post($this->url, [
                'json' => $payload,
                'timeout' => 2, // Быстрый таймаут, чтобы не блокировать ответ
            ]);

            return true;
        } catch (Throwable $e) {
            // Не выводим ничего, чтобы не сломать заголовки ответа
            // Логируем только в error_log, если нужно
            error_log('LokiLogger error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param Throwable|Exception $e
     *
     * @return bool
     */
    public function logException(Throwable|Exception $e): bool
    {
        $level = $e->getCode() === 1 ? 'warning' : 'error';

        Log::log($level, 'LokiLogger: ' . ParserMethods::maskBotToken($e->getMessage()), [
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        try {
            // Если URL не настроен, во внешний сборщик не отправляем
            if (empty($this->url)) {
                return true;
            }

            $payload = [
                'streams' => [
                    [
                        'stream' => [
                            'app' => config('app.name'),
                            'env' => config('app.env'),
                            'level' => $level,
                        ],
                        'values' => [
                            [
                                (string) (int) (microtime(true) * 1e9),
                                json_encode([
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'message' => $e->getMessage(),
                                ]),
                            ],
                        ],
                    ],
                ],
            ];

            $this->client->post($this->url, [
                'json' => $payload,
                'timeout' => 2, // Быстрый таймаут, чтобы не блокировать ответ
            ]);

            return true;
        } catch (Throwable $e) {
            // Не выводим ничего, чтобы не сломать заголовки ответа
            error_log('LokiLogger error: ' . $e->getMessage());
            return false;
        }
    }
}
