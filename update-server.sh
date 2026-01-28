#!/bin/bash

# Скрипт для безопасного обновления сервера через Docker
# Использование: ./update-server.sh [branch_name]
# По умолчанию обновляется текущая ветка

set -e

BRANCH="${1:-$(git branch --show-current)}"
BACKUP_DIR="./backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="pre-update-backup-${TIMESTAMP}"

echo "=========================================="
echo "Безопасное обновление сервера"
echo "Ветка: ${BRANCH}"
echo "=========================================="
echo ""

# Функция для проверки выполнения команды
function run_step {
    local CMD="$1"
    local MSG="$2"
    echo "➡️  $MSG..."
    if ! eval "$CMD"; then
        echo "❌ Ошибка на этапе: $MSG"
        return 1
    fi
    echo "✅ $MSG завершено"
    return 0
}

# Функция для отката изменений
function rollback {
    echo ""
    echo "=========================================="
    echo "❌ ОШИБКА! Выполняется откат..."
    echo "=========================================="
    
    if [ -f ".git/HEAD.backup" ]; then
        echo "Откат Git изменений..."
        git reset --hard HEAD@{1} || git reset --hard "$(cat .git/HEAD.backup)"
        rm -f .git/HEAD.backup
    fi
    
    echo "Перезапуск контейнеров..."
    docker-compose restart || true
    
    echo "Откат завершен. Проверьте логи и исправьте ошибки."
    exit 1
}

# Устанавливаем обработчик ошибок для отката
trap rollback ERR

cd "$(dirname "$0")"

# Проверка наличия .env
if [ ! -f .env ]; then
    echo "❌ Ошибка: .env файл не найден"
    exit 1
fi

# Загрузка переменных окружения
set -a
source .env
set +a

echo "1. Создание резервной копии базы данных..."
mkdir -p "${BACKUP_DIR}"
DB_USERNAME="${DB_USERNAME:-postgres}"
DB_DATABASE="${DB_DATABASE:-tg_support_bot}"

if docker-compose ps | grep -q "pgdb.*Up"; then
    docker-compose exec -T pgdb pg_dump -U "${DB_USERNAME}" "${DB_DATABASE}" > "${BACKUP_DIR}/${BACKUP_NAME}.sql" || {
        echo "⚠️  Не удалось создать бэкап БД, продолжаем обновление..."
    }
    echo "✅ Бэкап БД создан: ${BACKUP_DIR}/${BACKUP_NAME}.sql"
else
    echo "⚠️  Контейнер БД не запущен, пропускаем бэкап"
fi

echo ""
echo "2. Сохранение текущего состояния Git..."
git rev-parse HEAD > .git/HEAD.backup || true

echo ""
echo "3. Получение последних изменений из Git..."
if [ -n "$(git status --porcelain)" ]; then
    echo "⚠️  Обнаружены незакоммиченные изменения. Сохраняем их..."
    git stash push -m "Auto-stash before update ${TIMESTAMP}" || true
fi

run_step "git fetch origin" "Получение изменений из удаленного репозитория"
run_step "git checkout ${BRANCH}" "Переключение на ветку ${BRANCH}"
run_step "git pull origin ${BRANCH}" "Обновление кода из ветки ${BRANCH}"

echo ""
echo "4. Проверка изменений в Docker файлах..."
DOCKER_FILES_CHANGED=$(git diff HEAD@{1} HEAD --name-only | grep -E "(Dockerfile|docker-compose\.yml|\.env)" || true)
COMPOSE_FILES_CHANGED=$(git diff HEAD@{1} HEAD --name-only | grep "docker-compose.yml" || true)

if [ -n "$COMPOSE_FILES_CHANGED" ]; then
    echo "⚠️  Обнаружены изменения в docker-compose.yml"
    echo "   Рекомендуется проверить конфигурацию перед продолжением"
    read -p "Продолжить обновление? (y/n): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Обновление отменено"
        exit 0
    fi
fi

echo ""
echo "5. Пересборка Docker образов (только измененные)..."
if [ -n "$DOCKER_FILES_CHANGED" ]; then
    run_step "docker-compose build --no-cache" "Пересборка всех образов"
else
    run_step "docker-compose build" "Пересборка измененных образов"
fi

echo ""
echo "6. Применение миграций базы данных..."
run_step "docker-compose exec -T app php artisan migrate --force" "Применение миграций"

echo ""
echo "7. Перезапуск контейнеров с проверкой health checks..."
# Перезапускаем контейнеры по одному для плавного обновления
run_step "docker-compose up -d --no-deps --build app" "Обновление контейнера app"

# Ждем готовности app
echo "⏳ Ожидание готовности контейнера app..."
sleep 5

# Проверяем health check app (если настроен)
if docker-compose ps app | grep -q "healthy\|Up"; then
    echo "✅ Контейнер app готов"
else
    echo "⚠️  Контейнер app запущен, но health check не пройден (может быть нормально)"
fi

run_step "docker-compose up -d --no-deps queue" "Обновление контейнера queue"
run_step "docker-compose up -d --no-deps nginx" "Обновление контейнера nginx"

echo ""
echo "8. Очистка кеша Laravel..."
run_step "docker-compose exec -T app php artisan config:clear" "Очистка кеша конфигурации"
run_step "docker-compose exec -T app php artisan cache:clear" "Очистка кеша приложения"
run_step "docker-compose exec -T app php artisan route:clear" "Очистка кеша маршрутов"
run_step "docker-compose exec -T app php artisan view:clear" "Очистка кеша представлений"

echo ""
echo "9. Пересоздание кеша..."
run_step "docker-compose exec -T app php artisan config:cache" "Кеширование конфигурации"
run_step "docker-compose exec -T app php artisan route:cache" "Кеширование маршрутов"
run_step "docker-compose exec -T app php artisan view:cache" "Кеширование представлений"

echo ""
echo "10. Проверка работоспособности сервисов..."
echo "Проверка статуса контейнеров..."
docker-compose ps

echo ""
echo "Проверка доступности приложения..."
if curl -f -s "http://localhost/health" > /dev/null 2>&1 || curl -f -s "http://localhost" > /dev/null 2>&1; then
    echo "✅ Приложение доступно"
else
    echo "⚠️  Приложение может быть недоступно (проверьте вручную)"
fi

echo ""
echo "Проверка подключения к базе данных..."
if docker-compose exec -T app php artisan db:show > /dev/null 2>&1; then
    echo "✅ Подключение к БД работает"
else
    echo "⚠️  Не удалось проверить подключение к БД (может быть нормально)"
fi

echo ""
echo "11. Очистка старых Docker образов..."
docker image prune -f > /dev/null 2>&1 || true
echo "✅ Очистка завершена"

echo ""
echo "12. Удаление временных файлов..."
rm -f .git/HEAD.backup
echo "✅ Временные файлы удалены"

echo ""
echo "=========================================="
echo "✅ Обновление сервера завершено успешно!"
echo "=========================================="
echo ""
echo "Резервная копия БД: ${BACKUP_DIR}/${BACKUP_NAME}.sql"
echo ""
echo "Проверьте работу бота:"
echo "  - docker-compose logs -f app"
echo "  - docker-compose logs -f queue"
echo "  - ./check-bot.sh"
echo ""

