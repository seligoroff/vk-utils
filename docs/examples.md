# Примеры использования

## Ежедневная проверка активности в группах

```bash
# Первый запуск - сбор данных
php artisan vk:check --delay=0.5

# Повторный просмотр без запросов к API
php artisan vk:check --cached

# Экспорт результатов в различные форматы
php artisan vk:check --format=json --output=check_results.json
php artisan vk:check --format=csv --output=check_results.csv
php artisan vk:check --output=check_report.md  # автоматически Markdown

# Использование кеша с экспортом
php artisan vk:check --cached --format=markdown --output=daily_report.md
```

## Поиск контента для публикации

```bash
# Найти оригинальные посты без репостов
php artisan vk:candidate --owner=-12345678 --noreposts
```

## Проверка на дубли

```bash
# Найти все посты с определенным текстом
php artisan vk:dups --owner=-12345678 --text="важная новость"
```

## Анализ активности пользователя

```bash
# Найти все комментарии пользователя в группе
php artisan comments:find --owner=-12345678 --author=98765432
```

## Получение постов за период

```bash
# Получить посты за период и вывести в таблицу
php artisan vk:posts-get --owner=-12345678 --from=2024-01-01 --to=2024-01-31

# Сохранить в JSON файл
php artisan vk:posts-get --owner=-12345678 --from=2024-01-01 --format=json --output=posts.json

# Сохранить в базу данных (накопление данных, дубликаты пропускаются)
php artisan vk:posts-get --owner=-12345678 --from=2024-01-01 --to=2024-01-31 --db

# Обновить существующие записи и добавить новые
php artisan vk:posts-get --owner=-12345678 --from=2024-01-01 --db --update

# Очистить посты указанного владельца и загрузить заново
php artisan vk:posts-get --owner=-12345678 --from=2024-01-01 --db --clear

# Фильтрация постов с минимум 10 лайками
php artisan vk:posts-get --owner=-12345678 --from=2024-01-01 --min-likes=10
```

## Работа с несколькими группами одновременно

```bash
# Загрузить посты для первой группы
php artisan vk:posts-get --owner=-11111111 --from=2024-01-01 --db

# Загрузить посты для второй группы (данные первой группы останутся нетронутыми)
php artisan vk:posts-get --owner=-22222222 --from=2024-01-01 --db

# Обновить данные только для первой группы
php artisan vk:posts-get --owner=-11111111 --from=2024-01-01 --db --update

# Очистить и перезагрузить данные только для первой группы
# (данные второй группы останутся нетронутыми)
php artisan vk:posts-get --owner=-11111111 --from=2024-01-01 --db --clear
```

## Анализ эффективности постов

```bash
# Базовый анализ за месяц
php artisan vk:analytics --owner=-12345678

# Анализ за неделю с определением лучшего времени публикации
php artisan vk:analytics --owner=-12345678 --period=week --best-time

# Анализ с сравнением с предыдущим месяцем
php artisan vk:analytics --owner=-12345678 --period=month --compare=previous

# Анализ за произвольный период
php artisan vk:analytics --owner=-12345678 --period=2024-01-01:2024-01-31

# Анализ с топ-5 постов по ER
php artisan vk:analytics --owner=-12345678 --top=5 --metrics=er

# Анализ с фильтрацией по минимальной вовлеченности (10+ реакций)
php artisan vk:analytics --owner=-12345678 --min-engagement=10

# Экспорт результатов в JSON
php artisan vk:analytics --owner=-12345678 --format=json --output=analytics.json

# Экспорт в CSV (создаст несколько файлов в директории reports/)
php artisan vk:analytics --owner=-12345678 --format=csv --output=reports/analytics.csv

# Полный анализ с всеми опциями
php artisan vk:analytics \
  --owner=-12345678 \
  --period=month \
  --compare=previous \
  --best-time \
  --top=10 \
  --metrics=all \
  --timezone=Europe/Moscow \
  --format=table

# Еженедельный автоматический отчет (можно добавить в cron)
php artisan vk:analytics \
  --owner=-12345678 \
  --period=week \
  --compare=previous \
  --format=json \
  --output=/path/to/weekly_report_$(date +\%Y\%m\%d).json
```

**Примеры интерпретации результатов:**

1. **ER по дням недели** - показывает, в какие дни недели посты получают больше вовлеченности. Используйте это для планирования публикаций.

2. **Лучшее время публикации** - показывает часы с наивысшим средним ER. Рекомендуется публиковать в часы, отмеченные как "⭐ Лучшее" или "⭐ Хорошее".

3. **Топ-посты** - анализируйте топ-посты по разным метрикам, чтобы понять, какой контент работает лучше всего.

4. **Сравнение периодов** - отслеживайте динамику:
   - ⬆️ - рост более 5%
   - ➡️ - стабильно (от -5% до +5%)
   - ⬇️ - падение более 5%


