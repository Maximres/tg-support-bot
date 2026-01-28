#!/bin/bash

# Скрипт для безопасного обновления кода с main ветки
# Основан на рекомендациях из update-server.sh
# Запускайте на сервере

set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo "=========================================="
echo "Обновление кода с main ветки"
echo "=========================================="
echo ""

cd /var/www/tg-support-bot || {
    echo -e "${RED}Ошибка: директория не найдена${NC}"
    exit 1
}

# Проверка .env
if [ ! -f .env ]; then
    echo -e "${RED}✗ Файл .env не найден!${NC}"
    exit 1
fi

# Функция для проверки выполнения команды (из update-server.sh)
function run_step {
    local CMD="$1"
    local MSG="$2"
    echo "➡️  $MSG..."
    if ! eval "$CMD"; then
        echo -e "${RED}❌ Ошибка на этапе: $MSG${NC}"
        return 1
    fi
    echo -e "${GREEN}✅ $MSG завершено${NC}"
    return 0
}

# Функция для отката изменений (из update-server.sh)
function rollback {
    echo ""
    echo "=========================================="
    echo -e "${RED}❌ ОШИБКА! Выполняется откат...${NC}"
    echo "=========================================="
    
    if [ -f ".git/CURRENT_COMMIT.backup" ]; then
        echo "Откат Git изменений..."
        git reset --hard "$(cat .git/CURRENT_COMMIT.backup)" || git reset --hard HEAD@{1}
        rm -f .git/CURRENT_COMMIT.backup
    fi
    
    echo "Перезапуск контейнеров..."
    docker-compose restart app queue 2>/dev/null || true
    
    echo "Откат завершен. Проверьте логи и исправьте ошибки."
    exit 1
}

# Устанавливаем обработчик ошибок для отката
trap rollback ERR

# 1. СОЗДАНИЕ БЭКАПА
echo -e "${BLUE}1. Создание резервной копии...${NC}"
echo "----------------------------------------"

# Создаем бэкап БД
DB_NAME=$(grep "^DB_DATABASE=" .env | cut -d '=' -f2 | tr -d '"' | tr -d "'" | tr -d ' ' || echo "")
DB_USER=$(grep "^DB_USERNAME=" .env | cut -d '=' -f2 | tr -d '"' | tr -d "'" | tr -d ' ' || echo "")

if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
    BACKUP_DIR="/var/www/backups"
    mkdir -p "$BACKUP_DIR"
    BACKUP_FILE="$BACKUP_DIR/pre-update-$(date +%Y%m%d_%H%M%S).sql"
    
    docker-compose exec -T pgdb pg_dump -U "$DB_USER" "$DB_NAME" > "$BACKUP_FILE" 2>/dev/null || {
        echo -e "${YELLOW}⚠ Не удалось создать бэкап БД, продолжаем...${NC}"
    }
    
    if [ -f "$BACKUP_FILE" ] && [ -s "$BACKUP_FILE" ]; then
        echo -e "${GREEN}✓ Бэкап БД создан: $BACKUP_FILE${NC}"
    fi
fi

# Сохраняем текущий коммит
CURRENT_COMMIT=$(git rev-parse HEAD)
echo "$CURRENT_COMMIT" > .git/CURRENT_COMMIT.backup
echo -e "${GREEN}✓ Текущий коммит сохранен: ${CURRENT_COMMIT:0:7}${NC}"

# 2. ПРОВЕРКА ИЗМЕНЕНИЙ
echo ""
echo -e "${BLUE}2. Проверка локальных изменений...${NC}"
echo "----------------------------------------"

# Проверяем только отслеживаемые файлы (изменения в уже закоммиченных файлах)
TRACKED_CHANGES=$(git diff --name-only 2>/dev/null || echo "")
# Проверяем неотслеживаемые файлы
UNTRACKED_FILES=$(git ls-files --others --exclude-standard 2>/dev/null || echo "")

if [ -n "$TRACKED_CHANGES" ] || [ -n "$UNTRACKED_FILES" ]; then
    if [ -n "$TRACKED_CHANGES" ]; then
        echo -e "${YELLOW}⚠ Обнаружены изменения в отслеживаемых файлах:${NC}"
        echo "$TRACKED_CHANGES" | sed 's/^/  - /'
    fi
    
    if [ -n "$UNTRACKED_FILES" ]; then
        echo -e "${YELLOW}⚠ Обнаружены неотслеживаемые файлы:${NC}"
        echo "$UNTRACKED_FILES" | head -10 | sed 's/^/  - /'
        if [ $(echo "$UNTRACKED_FILES" | wc -l) -gt 10 ]; then
            echo "  ... и еще $(($(echo "$UNTRACKED_FILES" | wc -l) - 10)) файлов"
        fi
    fi
    
    echo ""
    echo "Выберите действие:"
    echo "  1) Сохранить все изменения в stash (включая неотслеживаемые файлы)"
    echo "  2) Продолжить без сохранения (неотслеживаемые файлы останутся)"
    echo "  3) Отменить обновление"
    read -p "Ваш выбор (1/2/3): " CHOICE
    
    case "$CHOICE" in
        1)
            echo "Сохранение всех изменений в stash..."
            git stash push -u -m "Auto-stash before pull $(date +%Y%m%d_%H%M%S)" || {
                echo -e "${YELLOW}⚠ Не удалось сохранить в stash, продолжаем без сохранения...${NC}"
            }
            echo -e "${GREEN}✓ Изменения сохранены в stash${NC}"
            ;;
        2)
            echo -e "${YELLOW}⚠ Продолжаем без сохранения изменений${NC}"
            echo "  Неотслеживаемые файлы останутся без изменений"
            
            # Проверяем конфликты с файлами из удаленного репозитория
            echo ""
            echo "Проверка конфликтов с файлами из удаленного репозитория..."
            git fetch origin main >/dev/null 2>&1 || true
            REMOTE_FILES=$(git ls-tree -r origin/main --name-only 2>/dev/null || echo "")
            
            if [ -n "$REMOTE_FILES" ] && [ -n "$UNTRACKED_FILES" ]; then
                CONFLICTS=""
                for file in $UNTRACKED_FILES; do
                    if echo "$REMOTE_FILES" | grep -q "^$file$"; then
                        CONFLICTS="${CONFLICTS}${file}\n"
                    fi
                done
                
                if [ -n "$CONFLICTS" ]; then
                    echo -e "${YELLOW}⚠ Найдены неотслеживаемые файлы, которые существуют в удаленном репозитории:${NC}"
                    echo -e "$CONFLICTS" | sed 's/^/  - /'
                    echo ""
                    echo "Эти файлы будут перезаписаны при merge."
                    echo "Локальные копии будут сохранены с расширением .local.backup"
                    
                    for file in $(echo -e "$CONFLICTS"); do
                        if [ -f "$file" ]; then
                            BACKUP_NAME="${file}.local.backup.$(date +%Y%m%d_%H%M%S)"
                            cp "$file" "$BACKUP_NAME" 2>/dev/null && echo "  Сохранено: $BACKUP_NAME" || true
                            rm -f "$file"
                            echo "  Удален локальный файл: $file"
                        fi
                    done
                fi
            fi
            ;;
        3)
            echo -e "${RED}✗ Обновление отменено${NC}"
            exit 0
            ;;
        *)
            echo -e "${RED}✗ Неверный выбор. Обновление отменено${NC}"
            exit 1
            ;;
    esac
else
    echo -e "${GREEN}✓ Локальных изменений нет${NC}"
fi

# 3. ПОЛУЧЕНИЕ ОБНОВЛЕНИЙ
echo ""
echo -e "${BLUE}3. Получение обновлений из main...${NC}"
echo "----------------------------------------"

# Проверяем текущую ветку
CURRENT_BRANCH=$(git branch --show-current)
echo "Текущая ветка: $CURRENT_BRANCH"

# Получаем изменения
echo "Получение изменений из удаленного репозитория..."
git fetch origin main || {
    echo -e "${RED}✗ Ошибка получения изменений${NC}"
    exit 1
}

# Проверяем, есть ли новые коммиты
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)

if [ "$LOCAL" = "$REMOTE" ]; then
    echo -e "${GREEN}✓ Уже на последней версии${NC}"
    echo ""
    echo "Новых изменений нет. Выход."
    exit 0
fi

echo "Новые коммиты найдены:"
git log --oneline HEAD..origin/main | head -5

# Переключаемся на main если нужно
if [ "$CURRENT_BRANCH" != "main" ]; then
    echo ""
    echo "Переключение на ветку main..."
    git checkout main || {
        echo -e "${YELLOW}⚠ Не удалось переключиться на main, пробуем обновить текущую ветку${NC}"
    }
fi

# Проверяем конфликты с неотслеживаемыми файлами перед merge
echo ""
echo "Проверка конфликтов с неотслеживаемыми файлами..."
UNTRACKED_NOW=$(git ls-files --others --exclude-standard 2>/dev/null || echo "")
REMOTE_FILES=$(git ls-tree -r origin/main --name-only 2>/dev/null || echo "")

if [ -n "$UNTRACKED_NOW" ] && [ -n "$REMOTE_FILES" ]; then
    CONFLICT_FILES=""
    for file in $UNTRACKED_NOW; do
        if echo "$REMOTE_FILES" | grep -q "^$file$"; then
            CONFLICT_FILES="${CONFLICT_FILES}${file}\n"
        fi
    done
    
    if [ -n "$CONFLICT_FILES" ]; then
        echo -e "${YELLOW}⚠ Найдены неотслеживаемые файлы, которые конфликтуют с удаленным репозиторием:${NC}"
        echo -e "$CONFLICT_FILES" | sed 's/^/  - /'
        echo ""
        echo "Сохраняем локальные копии и удаляем файлы для merge..."
        
        for file in $(echo -e "$CONFLICT_FILES"); do
            if [ -f "$file" ]; then
                BACKUP_NAME="${file}.local.backup.$(date +%Y%m%d_%H%M%S)"
                cp "$file" "$BACKUP_NAME" 2>/dev/null && echo "  ✓ Сохранено: $BACKUP_NAME" || true
                rm -f "$file"
                echo "  ✓ Удален локальный файл: $file"
            fi
        done
        echo ""
    else
        echo -e "${GREEN}✓ Конфликтов с неотслеживаемыми файлами не найдено${NC}"
    fi
fi

# Обновляем код
echo ""
echo "Обновление кода..."
run_step "git pull origin main" "Обновление кода из main ветки"

NEW_COMMIT=$(git rev-parse HEAD)
echo -e "${GREEN}✓ Код обновлен: ${NEW_COMMIT:0:7}${NC}"

# 4. ПРОВЕРКА ИЗМЕНЕНИЙ В КОНФИГУРАЦИИ
echo ""
echo -e "${BLUE}4. Проверка изменений...${NC}"
echo "----------------------------------------"

# Проверяем изменения в критических файлах
CRITICAL_CHANGES=$(git diff "$CURRENT_COMMIT" HEAD --name-only | grep -E "(docker-compose\.yml|Dockerfile|\.env\.example)" || echo "")

if [ -n "$CRITICAL_CHANGES" ]; then
    echo -e "${YELLOW}⚠ Обнаружены изменения в критических файлах:${NC}"
    echo "$CRITICAL_CHANGES" | sed 's/^/  - /'
    echo ""
    echo "Проверьте эти файлы перед продолжением!"
fi

# 5. ПРОВЕРКА ИЗМЕНЕНИЙ В DOCKER ФАЙЛАХ
echo ""
echo -e "${BLUE}5. Проверка изменений в Docker файлах...${NC}"
echo "----------------------------------------"

DOCKER_FILES_CHANGED=$(git diff "$CURRENT_COMMIT" HEAD --name-only | grep -E "(Dockerfile|docker-compose\.yml|\.env\.example)" || true)
COMPOSE_FILES_CHANGED=$(git diff "$CURRENT_COMMIT" HEAD --name-only | grep "docker-compose.yml" || true)

if [ -n "$COMPOSE_FILES_CHANGED" ]; then
    echo -e "${YELLOW}⚠ Обнаружены изменения в docker-compose.yml${NC}"
    echo "   Рекомендуется проверить конфигурацию перед продолжением"
    read -p "Продолжить обновление? (y/n): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Обновление отменено"
        exit 0
    fi
fi

# 6. ОБНОВЛЕНИЕ ЗАВИСИМОСТЕЙ
echo ""
echo -e "${BLUE}6. Обновление зависимостей...${NC}"
echo "----------------------------------------"

# Проверяем изменения в composer.json
if git diff "$CURRENT_COMMIT" HEAD --name-only | grep -q "composer.json\|composer.lock"; then
    run_step "docker-compose exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader" "Обновление зависимостей Composer"
else
    echo -e "${GREEN}✓ Изменений в зависимостях нет${NC}"
fi

# 7. ПЕРЕСБОРКА DOCKER ОБРАЗОВ
echo ""
echo -e "${BLUE}7. Пересборка Docker образов...${NC}"
echo "----------------------------------------"

if [ -n "$DOCKER_FILES_CHANGED" ]; then
    run_step "docker-compose build --no-cache app queue" "Пересборка всех образов"
else
    run_step "docker-compose build app queue" "Пересборка измененных образов"
fi

# 8. ПРИМЕНЕНИЕ МИГРАЦИЙ
echo ""
echo -e "${BLUE}8. Применение миграций базы данных...${NC}"
echo "----------------------------------------"

# Проверяем, есть ли новые миграции
NEW_MIGRATIONS=$(git diff "$CURRENT_COMMIT" HEAD --name-only | grep "database/migrations" || echo "")

if [ -n "$NEW_MIGRATIONS" ]; then
    echo "Обнаружены новые миграции:"
    echo "$NEW_MIGRATIONS" | sed 's/^/  - /'
    echo ""
    run_step "docker-compose exec -T app php artisan migrate --force" "Применение миграций"
else
    echo -e "${GREEN}✓ Новых миграций нет${NC}"
fi

# 9. ПЕРЕЗАПУСК КОНТЕЙНЕРОВ С ПРОВЕРКОЙ HEALTH CHECKS
echo ""
echo -e "${BLUE}9. Перезапуск контейнеров с проверкой health checks...${NC}"
echo "----------------------------------------"

# Перезапускаем контейнеры по одному для плавного обновления
run_step "docker-compose up -d --no-deps --build app" "Обновление контейнера app"

# Ждем готовности app
echo "⏳ Ожидание готовности контейнера app..."
sleep 5

# Проверяем health check app (если настроен)
if docker-compose ps app 2>/dev/null | grep -q "healthy\|Up"; then
    echo -e "${GREEN}✅ Контейнер app готов${NC}"
else
    echo -e "${YELLOW}⚠ Контейнер app запущен, но health check не пройден (может быть нормально)${NC}"
fi

run_step "docker-compose up -d --no-deps queue" "Обновление контейнера queue"

# Для nginx нужно сначала остановить и удалить старый контейнер, чтобы освободить порты
echo "⏳ Остановка старого контейнера nginx..."
docker-compose stop nginx 2>/dev/null || true
docker-compose rm -f nginx 2>/dev/null || true

# Удаляем все контейнеры nginx (включая остановленные)
docker ps -a | grep nginx | awk '{print $1}' | xargs -r docker rm -f 2>/dev/null || true

# Проверяем и останавливаем системный nginx, если он занимает порты
if systemctl is-active nginx >/dev/null 2>&1 || netstat -tlnp 2>/dev/null | grep -q ":80.*nginx" || lsof -i :80 2>/dev/null | grep -q nginx; then
    echo -e "${YELLOW}⚠ Обнаружен системный nginx, занимающий порт 80${NC}"
    echo "⏳ Остановка системного nginx..."
    systemctl stop nginx 2>/dev/null || true
    systemctl disable nginx 2>/dev/null || true
    
    # Если не остановился через systemctl, убиваем процессы
    NGINX_PIDS=$(ps aux | grep "nginx: master" | grep -v grep | awk '{print $2}' || echo "")
    if [ -n "$NGINX_PIDS" ]; then
        echo "$NGINX_PIDS" | xargs kill -TERM 2>/dev/null || true
        sleep 2
        echo "$NGINX_PIDS" | xargs kill -9 2>/dev/null || true
    fi
    
    echo -e "${GREEN}✓ Системный nginx остановлен${NC}"
    sleep 2
fi

# Пересоздаем конфигурацию nginx из шаблона
echo "⏳ Пересоздание конфигурации nginx..."
MAIN_DOMAIN=$(grep "^MAIN_DOMAIN=" .env | head -1 | cut -d '=' -f2- | sed 's/^["'\'']*//;s/["'\'']*$//' | tr -d ' ' | head -1)

if [ -z "$MAIN_DOMAIN" ]; then
    echo -e "${YELLOW}⚠ MAIN_DOMAIN не найден в .env, пропускаем пересоздание конфигурации${NC}"
else
    # Используем while read для надежной замены (работает с дефисами в домене)
    if [ -f docker/nginx/default.conf.template ]; then
        while IFS= read -r line; do
            echo "${line//__MAIN_DOMAIN__/$MAIN_DOMAIN}"
        done < docker/nginx/default.conf.template > docker/nginx/default.conf
        
        if [ -f docker/nginx/default.conf ] && [ -s docker/nginx/default.conf ]; then
            echo -e "${GREEN}✓ Конфигурация nginx пересоздана${NC}"
            
            # Проверяем что location /api/health/simple есть
            if grep -q "location /api/health/simple" docker/nginx/default.conf; then
                echo -e "${GREEN}✓ Health check endpoint настроен${NC}"
            else
                echo -e "${YELLOW}⚠ Health check endpoint не найден в конфигурации${NC}"
            fi
        else
            echo -e "${YELLOW}⚠ Не удалось создать конфигурацию nginx${NC}"
        fi
    else
        echo -e "${YELLOW}⚠ Шаблон конфигурации nginx не найден${NC}"
    fi
fi

# Проверяем, что порты освобождены
echo "Проверка освобождения портов..."
PORTS_IN_USE=$(netstat -tlnp 2>/dev/null | grep -E ':(80|443)' || lsof -i :80 -i :443 2>/dev/null || echo "")
if [ -n "$PORTS_IN_USE" ]; then
    echo -e "${YELLOW}⚠ Порты все еще заняты, ждем освобождения...${NC}"
    sleep 5
fi

# Пересоздаем конфигурацию nginx из шаблона перед запуском
echo "⏳ Пересоздание конфигурации nginx..."
MAIN_DOMAIN=$(grep "^MAIN_DOMAIN=" .env | head -1 | cut -d '=' -f2- | sed 's/^["'\'']*//;s/["'\'']*$//' | tr -d ' ' | head -1)

if [ -z "$MAIN_DOMAIN" ]; then
    echo -e "${YELLOW}⚠ MAIN_DOMAIN не найден в .env, пропускаем пересоздание конфигурации${NC}"
else
    # Используем while read для надежной замены (работает с дефисами в домене)
    if [ -f docker/nginx/default.conf.template ]; then
        while IFS= read -r line; do
            echo "${line//__MAIN_DOMAIN__/$MAIN_DOMAIN}"
        done < docker/nginx/default.conf.template > docker/nginx/default.conf
        
        if [ -f docker/nginx/default.conf ] && [ -s docker/nginx/default.conf ]; then
            echo -e "${GREEN}✓ Конфигурация nginx пересоздана${NC}"
            
            # Проверяем что location /api/health/simple есть
            if grep -q "location /api/health/simple" docker/nginx/default.conf; then
                echo -e "${GREEN}✓ Health check endpoint настроен${NC}"
            else
                echo -e "${YELLOW}⚠ Health check endpoint не найден в конфигурации${NC}"
            fi
        else
            echo -e "${YELLOW}⚠ Не удалось создать конфигурацию nginx${NC}"
        fi
    else
        echo -e "${YELLOW}⚠ Шаблон конфигурации nginx не найден${NC}"
    fi
fi

sleep 2

run_step "docker-compose up -d --no-deps nginx" "Обновление контейнера nginx"

# Ждем запуска nginx и проверяем health check
echo "⏳ Ожидание запуска nginx..."
sleep 5

# Проверяем health check
if curl -f -s http://localhost/api/health/simple > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Health check endpoint работает${NC}"
else
    echo -e "${YELLOW}⚠ Health check endpoint может быть недоступен (проверьте вручную)${NC}"
fi

# 10. ОЧИСТКА КЕША LARAVEL
echo ""
echo -e "${BLUE}10. Очистка кеша Laravel...${NC}"
echo "----------------------------------------"

run_step "docker-compose exec -T app php artisan config:clear" "Очистка кеша конфигурации"
run_step "docker-compose exec -T app php artisan cache:clear" "Очистка кеша приложения"
run_step "docker-compose exec -T app php artisan route:clear" "Очистка кеша маршрутов"
run_step "docker-compose exec -T app php artisan view:clear" "Очистка кеша представлений"

# 11. ПЕРЕСОЗДАНИЕ КЕША
echo ""
echo -e "${BLUE}11. Пересоздание кеша...${NC}"
echo "----------------------------------------"

run_step "docker-compose exec -T app php artisan config:cache" "Кеширование конфигурации"
run_step "docker-compose exec -T app php artisan route:cache" "Кеширование маршрутов"
run_step "docker-compose exec -T app php artisan view:cache" "Кеширование представлений"

# 12. ПРОВЕРКА РАБОТОСПОСОБНОСТИ
echo ""
echo -e "${BLUE}12. Проверка работоспособности сервисов...${NC}"
echo "----------------------------------------"

echo "Проверка статуса контейнеров..."
docker-compose ps | grep -v "WARN" || docker-compose ps

echo ""
echo "Проверка доступности приложения..."
# Проверяем health check endpoint (более надежно)
if curl -f -s "http://localhost/api/health/simple" > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Health check endpoint доступен${NC}"
elif curl -f -s "http://localhost/up" > /dev/null 2>&1 || curl -f -s "http://localhost" > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Приложение доступно${NC}"
else
    echo -e "${YELLOW}⚠ Приложение может быть недоступно (проверьте вручную)${NC}"
fi

echo ""
echo "Проверка подключения к базе данных..."
if docker-compose exec -T app php artisan migrate:status > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Подключение к БД работает${NC}"
else
    echo -e "${YELLOW}⚠ Не удалось проверить подключение к БД (может быть нормально)${NC}"
fi

# 13. ОЧИСТКА СТАРЫХ DOCKER ОБРАЗОВ
echo ""
echo -e "${BLUE}13. Очистка старых Docker образов...${NC}"
echo "----------------------------------------"
docker image prune -f > /dev/null 2>&1 || true
echo -e "${GREEN}✅ Очистка завершена${NC}"

# 14. УДАЛЕНИЕ ВРЕМЕННЫХ ФАЙЛОВ
echo ""
echo -e "${BLUE}14. Удаление временных файлов...${NC}"
echo "----------------------------------------"
rm -f .git/CURRENT_COMMIT.backup
echo -e "${GREEN}✅ Временные файлы удалены${NC}"

# Отключаем обработчик ошибок после успешного завершения
trap - ERR

echo ""
echo "=========================================="
echo -e "${GREEN}✅ Обновление сервера завершено успешно!${NC}"
echo "=========================================="
echo ""
echo "Изменения:"
echo "  Было: ${CURRENT_COMMIT:0:7}"
echo "  Стало: ${NEW_COMMIT:0:7}"
if [ -f "$BACKUP_FILE" ]; then
    echo ""
    echo "Резервная копия БД: $BACKUP_FILE"
fi
echo ""
echo "Проверьте работу бота:"
echo "  - docker-compose logs -f app"
echo "  - docker-compose logs -f queue"
echo "  - docker-compose logs -f nginx"
echo "  - ./check-bot.sh"
echo "  - curl http://localhost/api/health/simple"
echo ""
echo "Если nginx не запустился или health check не работает:"
echo "  - ./create-nginx-config-fixed.sh  # Пересоздать конфигурацию"
echo "  - docker-compose restart nginx     # Перезапустить nginx"
echo ""

