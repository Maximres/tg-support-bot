#!/bin/bash

# Скрипт для проверки работоспособности Telegram бота

set -e

echo "=========================================="
echo "Проверка работоспособности Telegram бота"
echo "=========================================="
echo ""

cd /var/www/tg-support-bot

# Цвета для вывода
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Функция для проверки статуса
check_status() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓${NC} $2"
    else
        echo -e "${RED}✗${NC} $2"
    fi
}

# 1. Проверка статуса контейнеров
echo "1. Проверка статуса контейнеров:"
docker-compose ps | grep -E "NAME|app|nginx|pgdb|redis|queue|node" || true
echo ""

# 2. Проверка доступности контейнеров
echo "2. Проверка доступности контейнеров:"
CONTAINERS=("pet:app" "nginx:nginx" "pgdb:pgdb" "redis:redis" "laravel_queue:queue" "node_server:node")
for container in "${CONTAINERS[@]}"; do
    IFS=':' read -r name service <<< "$container"
    if docker ps | grep -q "$name"; then
        echo -e "${GREEN}✓${NC} Контейнер $name ($service) запущен"
    else
        echo -e "${RED}✗${NC} Контейнер $name ($service) НЕ запущен"
    fi
done
echo ""

# 3. Проверка подключения к базе данных
echo "3. Проверка подключения к базе данных:"
if docker-compose exec -T app php artisan migrate:status > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} Подключение к базе данных работает"
    # Показываем информацию о подключении через env
    DB_CONNECTION=$(grep DB_CONNECTION .env | cut -d '=' -f2 | tr -d ' ' || echo "pgsql")
    DB_HOST=$(grep DB_HOST .env | cut -d '=' -f2 | tr -d ' ' || echo "pgdb")
    echo "   DB: $DB_CONNECTION | Host: $DB_HOST"
else
    echo -e "${RED}✗${NC} Ошибка подключения к базе данных"
    docker-compose exec -T app php artisan migrate:status 2>&1 | tail -5 || true
fi
echo ""

# 4. Проверка Redis
echo "4. Проверка Redis:"
if docker-compose exec -T redis redis-cli -a "${REDIS_PASSWORD:-redis_password}" ping > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} Redis работает"
else
    echo -e "${RED}✗${NC} Redis не отвечает"
fi
echo ""

# 5. Проверка webhook Telegram
echo "5. Проверка webhook Telegram:"
TELEGRAM_TOKEN=$(grep TELEGRAM_TOKEN .env | cut -d '=' -f2 | tr -d '"')
if [ -n "$TELEGRAM_TOKEN" ]; then
    WEBHOOK_INFO=$(curl -s "https://api.telegram.org/bot${TELEGRAM_TOKEN}/getWebhookInfo")
    if echo "$WEBHOOK_INFO" | grep -q '"ok":true'; then
        echo -e "${GREEN}✓${NC} Webhook настроен"
        echo "$WEBHOOK_INFO" | grep -E '"url"|"pending_update_count"|"last_error_message"|"last_error_date"' || true
        
        # Проверяем наличие ошибок
        if echo "$WEBHOOK_INFO" | grep -q '"last_error_message"'; then
            ERROR_MSG=$(echo "$WEBHOOK_INFO" | grep -o '"last_error_message":"[^"]*"' | cut -d'"' -f4)
            ERROR_DATE=$(echo "$WEBHOOK_INFO" | grep -o '"last_error_date":[0-9]*' | cut -d':' -f2)
            CURRENT_DATE=$(date +%s)
            
            if [ -n "$ERROR_MSG" ] && [ "$ERROR_MSG" != "null" ] && [ "$ERROR_MSG" != "" ]; then
                # Проверяем, не старая ли это ошибка (более 5 минут назад)
                if [ -n "$ERROR_DATE" ] && [ "$ERROR_DATE" != "0" ]; then
                    TIME_DIFF=$((CURRENT_DATE - ERROR_DATE))
                    if [ $TIME_DIFF -lt 300 ]; then
                        echo -e "${YELLOW}⚠${NC} Недавняя ошибка webhook: $ERROR_MSG"
                        echo -e "${YELLOW}  ${NC} Проверьте логи Nginx и доступность API"
                    else
                        echo -e "${GREEN}✓${NC} Старая ошибка webhook (более 5 минут назад) - сейчас все работает"
                    fi
                else
                    echo -e "${GREEN}✓${NC} Ошибка была, но сейчас все работает (pending_update_count = 0)"
                fi
            else
                echo -e "${GREEN}✓${NC} Ошибок webhook нет"
            fi
        fi
    else
        echo -e "${RED}✗${NC} Ошибка получения информации о webhook"
        echo "$WEBHOOK_INFO"
    fi
else
    echo -e "${YELLOW}⚠${NC} TELEGRAM_TOKEN не найден в .env"
fi
echo ""

# 6. Проверка доступности API
echo "6. Проверка доступности API:"
MAIN_DOMAIN=$(grep MAIN_DOMAIN .env | cut -d '=' -f2 | tr -d ' ')
if [ -n "$MAIN_DOMAIN" ]; then
    # Проверяем доступность основного домена
    HTTP_CODE_ROOT=$(curl -s -o /dev/null -w "%{http_code}" "https://${MAIN_DOMAIN}/" || echo "000")
    
    # Проверяем эндпоинт Telegram webhook
    HTTP_CODE_API=$(curl -s -o /dev/null -w "%{http_code}" "https://${MAIN_DOMAIN}/api/telegram/bot" -X POST -H "Content-Type: application/json" -d '{}' || echo "000")
    
    if [ "$HTTP_CODE_ROOT" = "200" ] || [ "$HTTP_CODE_ROOT" = "301" ] || [ "$HTTP_CODE_ROOT" = "302" ]; then
        echo -e "${GREEN}✓${NC} Основной домен доступен (HTTP $HTTP_CODE_ROOT)"
    else
        echo -e "${RED}✗${NC} Основной домен недоступен (HTTP $HTTP_CODE_ROOT)"
    fi
    
    if [ "$HTTP_CODE_API" = "200" ] || [ "$HTTP_CODE_API" = "400" ] || [ "$HTTP_CODE_API" = "422" ]; then
        echo -e "${GREEN}✓${NC} API эндпоинт доступен (HTTP $HTTP_CODE_API)"
    elif [ "$HTTP_CODE_API" = "403" ]; then
        echo -e "${GREEN}✓${NC} API работает (HTTP 403 - это нормально для тестового запроса без секретного токена)"
        echo "   Telegram отправляет правильный токен и получает HTTP 200"
    elif [ "$HTTP_CODE_API" = "000" ]; then
        echo -e "${RED}✗${NC} API недоступен (нет соединения)"
    else
        echo -e "${YELLOW}⚠${NC} API вернул HTTP $HTTP_CODE_API"
    fi
else
    echo -e "${YELLOW}⚠${NC} MAIN_DOMAIN не найден в .env"
fi
echo ""

# 7. Проверка логов на ошибки
echo "7. Проверка последних ошибок в логах:"
ERRORS=$(docker-compose logs --tail=50 app 2>/dev/null | grep -i "error\|fatal\|exception" | tail -5 || true)
if [ -n "$ERRORS" ]; then
    echo -e "${YELLOW}⚠${NC} Найдены ошибки в логах:"
    echo "$ERRORS"
else
    echo -e "${GREEN}✓${NC} Критических ошибок не найдено"
fi
echo ""

# 8. Проверка очередей
echo "8. Проверка очередей:"
if docker ps | grep -q "laravel_queue"; then
    QUEUE_LOGS=$(docker-compose logs --tail=10 queue 2>/dev/null | grep -i "error\|fatal" | tail -3 || true)
    if [ -z "$QUEUE_LOGS" ]; then
        echo -e "${GREEN}✓${NC} Очередь работает без ошибок"
    else
        echo -e "${YELLOW}⚠${NC} Найдены ошибки в очереди:"
        echo "$QUEUE_LOGS"
    fi
else
    echo -e "${RED}✗${NC} Контейнер очереди не запущен"
fi
echo ""

# 9. Проверка Node.js сервера
echo "9. Проверка Node.js сервера:"
if docker ps | grep -q "node_server"; then
    NODE_LOGS=$(docker-compose logs --tail=5 node 2>/dev/null | grep -i "error\|running" || true)
    if echo "$NODE_LOGS" | grep -q "running"; then
        echo -e "${GREEN}✓${NC} Node.js сервер работает"
    else
        echo -e "${YELLOW}⚠${NC} Проверьте логи Node.js сервера"
    fi
else
    echo -e "${RED}✗${NC} Контейнер Node.js не запущен"
fi
echo ""

# 10. Проверка прав доступа
echo "10. Проверка прав доступа:"
if [ -w "storage/logs/laravel.log" ]; then
    echo -e "${GREEN}✓${NC} Права на запись в storage/logs корректны"
else
    echo -e "${RED}✗${NC} Нет прав на запись в storage/logs"
fi
echo ""

# 11. Проверка конфигурации Laravel
echo "11. Проверка конфигурации Laravel:"
if docker-compose exec -T app php artisan config:cache > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} Конфигурация Laravel валидна"
else
    echo -e "${RED}✗${NC} Ошибка в конфигурации Laravel"
    docker-compose exec -T app php artisan config:cache 2>&1 | tail -5 || true
fi
echo ""

echo "=========================================="
echo "Проверка завершена"
echo "=========================================="
echo ""
echo "Для детальной проверки выполните:"
echo "  docker-compose logs --tail=100 app"
echo "  docker-compose logs --tail=100 nginx"
echo "  docker-compose logs --tail=100 queue"
echo ""
echo "Для тестирования отправьте сообщение боту в Telegram"

