<?php

namespace App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Повторная доставка одного и того же обновления Telegram не обрабатывается второй раз.
 *
 * Если ответ вебхука не пришёл вовремя, Telegram присылает то же обновление снова. Для долгой команды это
 * превращалось в цепочку одинаковых запусков, пока бот не отвечал остальным. Теперь повтор получает мгновенный «ok».
 */
class SkipDuplicateTelegramUpdate
{
    /** Telegram повторяет доставку не дольше суток */
    private const TTL_HOURS = 24;

    /**
     * @param Request                                                        $request
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     *
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $updateId = (int)$request->input('update_id', 0);

        if ($updateId > 0 && $this->alreadySeen($request->path(), $updateId)) {
            Log::info('SkipDuplicateTelegramUpdate: повторная доставка пропущена', ['update_id' => $updateId]);

            return response()->json(['ok' => true]);
        }

        return $next($request);
    }

    /**
     * Запоминает обновление и сообщает, было ли оно уже получено
     *
     * @param string $path
     * @param int    $updateId
     *
     * @return bool
     */
    private function alreadySeen(string $path, int $updateId): bool
    {
        try {
            // add() записывает ключ только если его ещё нет, поэтому первая доставка проходит, а повторы нет
            return !Cache::add("tg_update:{$path}:{$updateId}", 1, now()->addHours(self::TTL_HOURS));
        } catch (\Throwable $e) {
            // Не удалось обратиться к кэшу — лучше обработать обновление, чем потерять его
            Log::warning('SkipDuplicateTelegramUpdate: кэш недоступен', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
