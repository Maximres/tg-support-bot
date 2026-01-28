#!/bin/bash

# Скрипт мониторинга бота с отправкой уведомлений в Telegram
# Использование: добавьте в crontab для автоматического запуска
# */5 * * * * /var/www/tg-support-bot/monitor-bot.sh

set -e

cd /var/www/tg-support-bot

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Получаем настройки из .env
TELEGRAM_TOKEN=$(grep TELEGRAM_TOKEN .env | cut -d '=' -f2 | tr -d '"')
TELEGRAM_GROUP_ID=$(grep TELEGRAM_GROUP_ID .env | cut -d '=' -f2 | tr -d '"')
MAIN_DOMAIN=$(grep MAIN_DOMAIN .env | cut -d '=' -f2 | tr -d ' ')

# Функция отправки уведомления в Telegram
send_notification() {
    local message="$1"
    local chat_id="${TELEGRAM_GROUP_ID}"
    
    if [ -z "$chat_id" ] || [ -z "$TELEGRAM_TOKEN" ]; then
        echo "⚠ Telegram настройки не найдены, уведомление не отправлено"
        return
    fi
    
    curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_TOKEN}/sendMessage" \
        -d "chat_id=${chat_id}" \
        -d "text=$(echo -e "$message")" \
        -d "parse_mode=HTML" \
        > /dev/null 2>&1
}

# Функция проверки контейнера
check_container() {
    local container_name="$1"
    if ! docker ps --format "{{.Names}}" | grep -q "^${container_name}$"; then
        return 1
    fi
    return 0
}

# Функция проверки HTTP endpoint
check_http() {
    local url="$1"
    local http_code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$url" 2>/dev/null || echo "000")
    
    if [ "$http_code" = "200" ] || [ "$http_code" = "503" ]; then
        return 0
    fi
    return 1
}

# Массив для хранения проблем
PROBLEMS=()

echo "$(date '+%Y-%m-%d %H:%M:%S') - Проверка бота..."

# 1. Проверка контейнеров
REQUIRED_CONTAINERS=("pet" "nginx" "pgdb" "redis" "laravel_queue")
for container in "${REQUIRED_CONTAINERS[@]}"; do
    if ! check_container "$container"; then
        PROBLEMS+=("❌ Контейнер $container не запущен")
    fi
done

# 2. Проверка HTTP доступности
if [ -n "$MAIN_DOMAIN" ]; then
    if ! check_http "https://${MAIN_DOMAIN}/up"; then
        PROBLEMS+=("❌ Health endpoint недоступен")
    fi
    
    if ! check_http "https://${MAIN_DOMAIN}/api/health/simple"; then
        PROBLEMS+=("❌ API health check недоступен")
    fi
fi

# 3. Проверка базы данных
if ! docker-compose exec -T app php artisan migrate:status > /dev/null 2>&1; then
    PROBLEMS+=("❌ База данных недоступна")
fi

# 4. Проверка Redis
REDIS_PASSWORD=$(grep REDIS_PASSWORD .env | cut -d '=' -f2 | tr -d '"')
if ! docker-compose exec -T redis redis-cli -a "${REDIS_PASSWORD}" ping > /dev/null 2>&1; then
    PROBLEMS+=("❌ Redis недоступен")
fi

# 5. Проверка webhook Telegram
if [ -n "$TELEGRAM_TOKEN" ]; then
    WEBHOOK_INFO=$(curl -s --max-time 10 "https://api.telegram.org/bot${TELEGRAM_TOKEN}/getWebhookInfo" 2>/dev/null || echo "")
    if [ -n "$WEBHOOK_INFO" ]; then
        PENDING=$(echo "$WEBHOOK_INFO" | grep -o '"pending_update_count":[0-9]*' | cut -d':' -f2 || echo "0")
        ERROR_MSG=$(echo "$WEBHOOK_INFO" | grep -o '"last_error_message":"[^"]*"' | cut -d'"' -f4 || echo "")
        
        if [ "$PENDING" -gt 50 ]; then
            PROBLEMS+=("⚠ Накопилось $PENDING необработанных обновлений в webhook")
        fi
        
        if [ -n "$ERROR_MSG" ] && [ "$ERROR_MSG" != "null" ] && [ "$ERROR_MSG" != "" ]; then
            PROBLEMS+=("⚠ Ошибка webhook: $ERROR_MSG")
        fi
    fi
fi

# 6. Проверка дискового пространства
DISK_USAGE=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//')
if [ "$DISK_USAGE" -gt 90 ]; then
    PROBLEMS+=("❌ Диск заполнен на ${DISK_USAGE}%")
elif [ "$DISK_USAGE" -gt 80 ]; then
    PROBLEMS+=("⚠ Диск заполнен на ${DISK_USAGE}%")
fi

# 7. Проверка логов на критические ошибки
RECENT_ERRORS=$(docker-compose logs --since 5m app 2>/dev/null | grep -i "fatal\|error" | tail -5 || true)
if [ -n "$RECENT_ERRORS" ]; then
    ERROR_COUNT=$(echo "$RECENT_ERRORS" | wc -l)
    PROBLEMS+=("⚠ Найдено $ERROR_COUNT ошибок в логах за последние 5 минут")
fi

# Обработка результатов
if [ ${#PROBLEMS[@]} -eq 0 ]; then
    echo -e "${GREEN}✓ Все проверки пройдены${NC}"
    exit 0
else
    echo -e "${RED}Обнаружены проблемы:${NC}"
    for problem in "${PROBLEMS[@]}"; do
        echo "  $problem"
    done
    
    # Формируем сообщение для Telegram
    MESSAGE="<b>⚠ Проблемы с ботом</b>\n\n"
    MESSAGE+="Время: $(date '+%Y-%m-%d %H:%M:%S')\n"
    MESSAGE+="Сервер: $(hostname)\n\n"
    
    for problem in "${PROBLEMS[@]}"; do
        MESSAGE+="$problem\n"
    done
    
    MESSAGE+="\nПроверьте логи: docker-compose logs --tail=50 app"
    
    # Отправляем уведомление
    send_notification "$MESSAGE"
    
    exit 1
fi


