#!/usr/bin/env bash
#
# Ежедневный бэкап БД бота.
#
#  1. pg_dump -> gzip -> локальная копия в $BACKUP_DIR (хранится $KEEP_DAYS дней)
#  2. дамп + .env упаковываются в архив, шифруются (AES-256, пароль из $PASS_FILE)
#     и отправляются документом в Telegram-чат $BACKUP_TELEGRAM_CHAT_ID —
#     это копия ВНЕ сервера: если сервер пропадёт целиком, данные останутся
#  3. при любой ошибке в тот же чат приходит сообщение о сбое
#
# Настройка (в .env проекта): BACKUP_TELEGRAM_CHAT_ID=<id чата, куда слать копии>
# Расшифровка и восстановление: см. scripts/restore-db.sh

set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-/var/www/tg-support-bot}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/tg-support-bot}"
KEEP_DAYS="${KEEP_DAYS:-14}"
PASS_FILE="${PASS_FILE:-/root/.backup_passphrase}"
MAX_UPLOAD_BYTES=$((45 * 1024 * 1024)) # лимит Telegram для ботов — 50 МБ

cd "$PROJECT_DIR"

env_get() {
    grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"' || true
}

DB_USER="$(env_get DB_USERNAME)"
DB_NAME="$(env_get DB_DATABASE)"
TOKEN="$(env_get TELEGRAM_TOKEN)"
CHAT_ID="$(env_get BACKUP_TELEGRAM_CHAT_ID)"

notify() {
    [ -n "$TOKEN" ] && [ -n "$CHAT_ID" ] || return 0
    curl -s -4 -m 30 -o /dev/null "https://api.telegram.org/bot${TOKEN}/sendMessage" \
        --data-urlencode "chat_id=${CHAT_ID}" --data-urlencode "text=$1" || true
}

trap 'notify "❌ Бэкап БД tg-support-bot не удался на $(hostname) ($(date -u +%F\ %T) UTC). Смотрите /var/log/tg-support-bot-backup.log"' ERR

umask 077
mkdir -p "$BACKUP_DIR"

STAMP="$(date -u +%Y%m%d-%H%M%S)"
DUMP="$BACKUP_DIR/db-$STAMP.sql.gz"

docker compose exec -T pgdb pg_dump -U "$DB_USER" --no-owner "$DB_NAME" | gzip -9 > "$DUMP"

# Дамп должен быть целым архивом и не пустым (пустая БД с таблицами всё равно > 1 КБ)
gzip -t "$DUMP"
[ "$(stat -c %s "$DUMP")" -gt 1024 ] || { echo "Дамп подозрительно маленький: $DUMP" >&2; false; }

# Копия вне сервера
if [ -n "$TOKEN" ] && [ -n "$CHAT_ID" ] && [ -f "$PASS_FILE" ]; then
    TMP="$(mktemp -d)"
    trap 'rm -rf "$TMP"' EXIT

    cp "$DUMP" "$TMP/db.sql.gz"
    cp .env "$TMP/env"
    tar -C "$TMP" -czf "$TMP/bundle.tar.gz" db.sql.gz env
    openssl enc -aes-256-cbc -pbkdf2 -salt -in "$TMP/bundle.tar.gz" -out "$TMP/backup-$STAMP.tar.gz.enc" -pass "file:$PASS_FILE"

    SIZE="$(stat -c %s "$TMP/backup-$STAMP.tar.gz.enc")"
    if [ "$SIZE" -le "$MAX_UPLOAD_BYTES" ]; then
        curl -sS -4 -m 120 -o /dev/null -f "https://api.telegram.org/bot${TOKEN}/sendDocument" \
            -F "chat_id=${CHAT_ID}" \
            -F "document=@$TMP/backup-$STAMP.tar.gz.enc" \
            -F "caption=🗄 Бэкап БД tg-support-bot, $STAMP UTC ($((SIZE / 1024)) КБ), зашифрован"
    else
        notify "⚠️ Бэкап БД слишком большой для отправки в Telegram (${SIZE} байт). Локальная копия: $DUMP"
    fi
fi

# Ротация локальных копий
find "$BACKUP_DIR" -name 'db-*.sql.gz' -mtime +"$KEEP_DAYS" -delete

echo "[$(date -u +%FT%TZ)] OK $DUMP ($(stat -c %s "$DUMP") байт)"
