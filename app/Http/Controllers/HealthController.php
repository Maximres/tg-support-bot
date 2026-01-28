<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /**
     * Расширенная проверка здоровья системы
     *
     * @return JsonResponse
     */
    public function check(): JsonResponse
    {
        $checks = [
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'checks' => [],
        ];

        // Проверка базы данных
        try {
            DB::connection()->getPdo();
            $checks['checks']['database'] = [
                'status' => 'ok',
                'message' => 'Database connection successful',
            ];
        } catch (\Exception $e) {
            $checks['status'] = 'error';
            $checks['checks']['database'] = [
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage(),
            ];
        }

        // Проверка Redis
        try {
            Redis::connection()->ping();
            $checks['checks']['redis'] = [
                'status' => 'ok',
                'message' => 'Redis connection successful',
            ];
        } catch (\Exception $e) {
            $checks['status'] = 'error';
            $checks['checks']['redis'] = [
                'status' => 'error',
                'message' => 'Redis connection failed: ' . $e->getMessage(),
            ];
        }

        // Проверка webhook Telegram
        try {
            $token = config('traffic_source.settings.telegram.token');
            if ($token) {
                $webhookInfo = file_get_contents(
                    "https://api.telegram.org/bot{$token}/getWebhookInfo"
                );
                $webhookData = json_decode($webhookInfo, true);
                
                if ($webhookData && $webhookData['ok']) {
                    $result = $webhookData['result'];
                    $checks['checks']['telegram_webhook'] = [
                        'status' => 'ok',
                        'url' => $result['url'] ?? 'not set',
                        'pending_updates' => $result['pending_update_count'] ?? 0,
                        'last_error' => $result['last_error_message'] ?? null,
                    ];
                    
                    // Если есть ошибки или много накопленных обновлений
                    if (!empty($result['last_error_message']) || ($result['pending_update_count'] ?? 0) > 10) {
                        $checks['status'] = 'warning';
                    }
                } else {
                    $checks['status'] = 'error';
                    $checks['checks']['telegram_webhook'] = [
                        'status' => 'error',
                        'message' => 'Failed to get webhook info',
                    ];
                }
            }
        } catch (\Exception $e) {
            $checks['status'] = 'warning';
            $checks['checks']['telegram_webhook'] = [
                'status' => 'warning',
                'message' => 'Could not check webhook: ' . $e->getMessage(),
            ];
        }

        // Проверка дискового пространства
        try {
            $freeSpace = disk_free_space('/');
            $totalSpace = disk_total_space('/');
            $usedPercent = (1 - ($freeSpace / $totalSpace)) * 100;
            
            $checks['checks']['disk'] = [
                'status' => $usedPercent > 90 ? 'error' : ($usedPercent > 80 ? 'warning' : 'ok'),
                'free_space_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
                'total_space_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
                'used_percent' => round($usedPercent, 2),
            ];
            
            if ($usedPercent > 90) {
                $checks['status'] = 'error';
            } elseif ($usedPercent > 80 && $checks['status'] === 'ok') {
                $checks['status'] = 'warning';
            }
        } catch (\Exception $e) {
            $checks['checks']['disk'] = [
                'status' => 'warning',
                'message' => 'Could not check disk space',
            ];
        }

        $httpCode = match ($checks['status']) {
            'error' => 503,
            'warning' => 200,
            default => 200,
        };

        return response()->json($checks, $httpCode);
    }

    /**
     * Простая проверка (для внешних мониторингов)
     *
     * @return JsonResponse
     */
    public function simple(): JsonResponse
    {
        try {
            // Быстрая проверка базы данных
            DB::connection()->getPdo();
            
            return response()->json([
                'status' => 'ok',
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Service unavailable',
            ], 503);
        }
    }
}


