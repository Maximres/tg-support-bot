<?php

namespace App\Logging;

use Exception;
use GuzzleHttp\Client;
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
        try {
            // Если URL не настроен, просто возвращаем true (не логируем)
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
        try {
            // Если URL не настроен, просто возвращаем true (не логируем)
            if (empty($this->url)) {
                return true;
            }

            $level = $e->getCode() === 1 ? 'warning' : 'error';

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
