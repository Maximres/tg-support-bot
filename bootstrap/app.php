<?php

use App\Logging\LokiLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);

        $exceptions->render(function (RouteNotFoundException $e, Request $request) {
            return response()->json([
                'message' => 'Route not found.',
            ], 404);
        });

        /**
         * Sending log in Loki
         * Обернуто в try-catch, чтобы ошибки логирования не ломали ответ
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            try {
                // Проверяем, что Loki настроен и доступен
                $lokiUrl = config('loki_custom.url');
                if ($lokiUrl && filter_var($lokiUrl, FILTER_VALIDATE_URL)) {
                    (new LokiLogger())->sendBasicLog($e);
                }
            } catch (\Throwable $logError) {
                // Игнорируем ошибки логирования, чтобы не сломать ответ
                error_log('Failed to log to Loki: ' . $logError->getMessage());
            }
            
            if (env('APP_DEBUG') === false) {
                return response('ok', 200);
            }
        });
    })->create();
