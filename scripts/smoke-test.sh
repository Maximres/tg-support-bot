#!/usr/bin/env bash
#
# Проверка бота после выкатки на прод. Только чтение: ничего не меняет.
#
#   scripts/smoke-test.sh
#
# Проверяет: контейнеры работают, сайт отвечает, webhook без ошибок и с пустой очередью,
# в журнале нет свежих production.ERROR (кроме мусорных запросов сканеров).
# Код выхода: 0 — всё хорошо, 1 — есть проблемы.

set -uo pipefail

PROJECT_DIR="${PROJECT_DIR:-/var/www/tg-support-bot}"
cd "$PROJECT_DIR" || { echo "Нет каталога $PROJECT_DIR" >&2; exit 1; }

PROBLEMS=0
ok()   { echo "✅ $1"; }
fail() { echo "❌ $1"; PROBLEMS=$((PROBLEMS + 1)); }

env_value() {
    grep -m1 "^$1=" .env | cut -d= -f2- | tr -d '"'
}

# 1. Контейнеры
for service in app queue scheduler pgdb redis nginx; do
    status=$(docker compose ps --format '{{.Service}} {{.Status}}' 2>/dev/null | awk -v s="$service" '$1 == s {$1=""; print}')
    if [[ "$status" == *"Up"* && "$status" != *"unhealthy"* && "$status" != *"starting"* ]]; then
        ok "Контейнер $service: ${status# }"
    else
        fail "Контейнер $service: ${status:-не найден}"
    fi
done

# 2. Сайт
APP_URL=$(env_value APP_URL)
code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "${APP_URL}/api/health/simple" || echo 000)
if [ "$code" = "200" ]; then ok "Сайт отвечает (${APP_URL}): HTTP $code"; else fail "Сайт не отвечает (${APP_URL}): HTTP $code"; fi

# 3. Webhook
TOKEN=$(env_value TELEGRAM_TOKEN)
info=$(curl -s -m 20 "https://api.telegram.org/bot${TOKEN}/getWebhookInfo" || true)
webhook_url=$(echo "$info" | python3 -c "import sys,json; print(json.load(sys.stdin)['result'].get('url',''))" 2>/dev/null || echo "")
pending=$(echo "$info" | python3 -c "import sys,json; print(json.load(sys.stdin)['result'].get('pending_update_count',-1))" 2>/dev/null || echo -1)
last_error=$(echo "$info" | python3 -c "import sys,json; r=json.load(sys.stdin)['result']; print(r.get('last_error_message',''))" 2>/dev/null || echo "")

if [ -n "$webhook_url" ]; then ok "Webhook задан: $webhook_url"; else fail "Webhook не задан"; fi
if [ "$pending" = "0" ]; then ok "Очередь обновлений Telegram пуста"; else fail "В очереди Telegram обновлений: $pending"; fi
if [ -z "$last_error" ]; then ok "Telegram не сообщает об ошибках доставки"; else fail "Последняя ошибка webhook: $last_error"; fi

# 4. Свежие ошибки в журнале (последние 5 минут)
since=$(date -d '5 minutes ago' '+%Y-%m-%d %H:%M' 2>/dev/null || date '+%Y-%m-%d %H:%M')
errors=$(docker compose exec -T app sh -c "grep -h 'production.ERROR' storage/logs/laravel-\$(date +%Y-%m-%d).log 2>/dev/null" </dev/null \
    | grep -v -E 'could not be found|is not supported for route' \
    | awk -v since="$since" '{ ts = substr($1, 2) " " substr($2, 1, 5); if (ts >= since) print }' | tail -5 || true)
if [ -z "$errors" ]; then ok "Свежих ошибок в журнале нет"; else fail "Свежие ошибки в журнале:"; echo "$errors" | cut -c1-200; fi

echo
if [ "$PROBLEMS" -eq 0 ]; then echo "Итог: всё в порядке"; exit 0; fi
echo "Итог: проблем — $PROBLEMS"
exit 1
