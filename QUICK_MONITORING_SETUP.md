# Быстрая настройка мониторинга

## Шаг 1: Настройка скрипта мониторинга

На сервере выполните:

```bash
cd /var/www/tg-support-bot

# Сделайте скрипт исполняемым
chmod +x monitor-bot.sh

# Проверьте настройки в .env
grep TELEGRAM_GROUP_ID .env
# Если нет, добавьте ID группы для уведомлений
```

## Шаг 2: Добавьте в crontab

```bash
crontab -e
```

Добавьте строку (проверка каждые 5 минут):
```cron
*/5 * * * * /var/www/tg-support-bot/monitor-bot.sh >> /var/log/bot-monitor.log 2>&1
```

## Шаг 3: Тестирование

```bash
# Запустите вручную
./monitor-bot.sh

# Проверьте, что уведомление пришло в Telegram группу
```

## Шаг 4: Настройка внешнего мониторинга (опционально)

### UptimeRobot (бесплатно, 50 мониторов)

1. Зайдите на https://uptimerobot.com
2. Зарегистрируйтесь
3. Добавьте новый монитор:
   - **Type:** HTTP(s)
   - **URL:** `https://tg-support-bot-garetski.ru/api/health/simple`
   - **Interval:** 5 minutes
   - **Alert Contacts:** Ваш email

## Готово!

Теперь вы будете получать уведомления:
- В Telegram группу (через monitor-bot.sh)
- На email (через UptimeRobot)

## Проверка health endpoints

```bash
# Простая проверка
curl https://tg-support-bot-garetski.ru/api/health/simple

# Расширенная проверка
curl https://tg-support-bot-garetski.ru/api/health | jq
```


