#!/usr/bin/env bash
#
# Восстановление БД бота из дампа.
#
# Из локальной копии:
#   scripts/restore-db.sh /var/backups/tg-support-bot/db-YYYYmmdd-HHMMSS.sql.gz --yes
#
# Из копии, пришедшей в Telegram (backup-*.tar.gz.enc):
#   openssl enc -d -aes-256-cbc -pbkdf2 -in backup-XXXX.tar.gz.enc -out bundle.tar.gz -pass file:/root/.backup_passphrase
#   tar -xzf bundle.tar.gz          # даст db.sql.gz и env (копию .env)
#   scripts/restore-db.sh db.sql.gz --yes
#
# ВНИМАНИЕ: текущая схема public будет полностью удалена и заменена содержимым дампа.

set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-/var/www/tg-support-bot}"
DUMP="${1:-}"

if [ -z "$DUMP" ] || [ ! -f "$DUMP" ]; then
    echo "Использование: $0 <db.sql.gz> --yes" >&2
    exit 1
fi

if [ "${2:-}" != "--yes" ]; then
    echo "Текущие данные в БД будут удалены. Повторите с флагом --yes, если уверены." >&2
    exit 1
fi

cd "$PROJECT_DIR"

env_get() {
    grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"'
}

DB_USER="$(env_get DB_USERNAME)"
DB_NAME="$(env_get DB_DATABASE)"

gzip -t "$DUMP"

# Останавливаем приложение на время восстановления, чтобы никто не писал в БД
docker compose stop app queue nginx

docker compose exec -T pgdb psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 \
    -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
gunzip -c "$DUMP" | docker compose exec -T pgdb psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -q

docker compose start app queue nginx

echo "Восстановлено из $DUMP"
