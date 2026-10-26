#!/usr/bin/env bash
#
# Проверка: тестовый стенд соответствует проду (код, зависимости, окружение, меню).
# Запускать из корня проекта перед проверкой изменений на стенде.
#
#   scripts/check-staging-parity.sh
#
# Код выхода 1 — найдены расхождения, из-за которых проверка на стенде не считается достоверной.

set -uo pipefail
cd "$(dirname "$0")/.."

COMPOSE=(docker compose -p tgstaging -f docker-compose.staging.yml)
PROBLEMS=0
ok()   { echo "✅ $1"; }
warn() { echo "⚠️  $1"; }
fail() { echo "❌ $1"; PROBLEMS=$((PROBLEMS + 1)); }

git fetch -q origin 2>/dev/null || warn "git fetch не удался — сравниваю с последним известным origin/main"
BASE=$(git rev-parse --short origin/main)
echo "Прод = origin/main ($BASE); текущая ветка: $(git branch --show-current) ($(git rev-parse --short HEAD))"

# 1. Зависимости: файлы composer должны совпадать с продом
for f in composer.json composer.lock; do
    if git diff --quiet origin/main -- "$f" && git diff --quiet -- "$f"; then ok "$f совпадает с продом"; else fail "$f отличается от прода"; fi
done

# 2. Код: что отличается от прода (ожидаемо — только то, что вы проверяете сейчас)
changed=$(git diff --name-only origin/main -- app resources config routes database | wc -l | tr -d ' ')
dirty=$(git status --short -- app resources config routes database | wc -l | tr -d ' ')
echo "Файлов кода отличается от прода: $changed (из них не закоммичено: $dirty) — это изменения, которые проверяются на стенде"

# 3. Окружение контейнера
if "${COMPOSE[@]}" ps --status running --services 2>/dev/null | grep -q '^app$'; then
    php_version=$("${COMPOSE[@]}" exec -T app php -r 'echo PHP_VERSION;' </dev/null)
    echo "PHP на стенде: $php_version"
    lock_stage=$("${COMPOSE[@]}" exec -T app sh -c 'sha1sum composer.lock | cut -c1-12' </dev/null)
    lock_prod=$(git show origin/main:composer.lock | sha1sum | cut -c1-12)
    if [ "$lock_stage" = "$lock_prod" ]; then ok "composer.lock в контейнере = проду ($lock_prod)"; else warn "composer.lock в контейнере ($lock_stage) отличается от origin/main ($lock_prod)"; fi
    pg=$("${COMPOSE[@]}" exec -T db psql -U staging -d staging -t -A -c 'show server_version' </dev/null | cut -d' ' -f1)
    echo "PostgreSQL на стенде: $pg (на проде: образ postgres:15)"
    echo
    echo "== Меню на стенде (сравните с продом: scripts/smoke-test.sh не меняет данные)"
    "${COMPOSE[@]}" exec -T app php artisan tinker --execute='
        $m = new App\Actions\Telegram\SendAccessMessage;
        foreach ([false, true] as $access) {
            echo $access ? "-- с доступом\n" : "-- без доступа\n";
            foreach ($m->getKeyboard($access) as $row) { echo "[ ", implode(" | ", array_column($row, "text")), " ]\n"; }
        }
        echo "договор: ", App\Services\Rental\OfferDocument::fileId() ? "задан" : "НЕ задан", "\n";
        echo "правила: ", App\Models\SafeCode::current(App\Enums\SafeCodeType::ORG_LINK) ? "заданы" : "НЕ заданы", "\n";' </dev/null
else
    fail "Стенд не запущен: docker compose -p tgstaging -f docker-compose.staging.yml up -d"
fi

echo
if [ "$PROBLEMS" -eq 0 ]; then echo "Итог: расхождений с продом, портящих проверку, нет"; exit 0; fi
echo "Итог: расхождений — $PROBLEMS"
exit 1
