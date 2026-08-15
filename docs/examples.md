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

## Просмотр постов из базы данных

```bash
# Прочитать посты хронологически, без запросов к VK API
php artisan vk:posts-list --owner=-12345678 --from=2026-06-01 --to=2026-09-01

# Получить полные тексты в JSON
php artisan vk:posts-list --owner=-12345678 --from=2026-06-01 --to=2026-09-01 --limit=100 --format=json

# Перейти ко второй странице
php artisan vk:posts-list --owner=-12345678 --from=2026-06-01 --to=2026-09-01 --limit=50 --offset=50

# Сохранить полный читаемый отчёт
php artisan vk:posts-list --owner=-12345678 --from=2026-06-01 --to=2026-09-01 --format=markdown --full-text --output=reports/posts.md
```

## Массовое получение постов для всех групп

```bash
# Получить посты за последний месяц для всех групп из vk-groups.csv
php artisan vk:posts-get-all --from="last month"

# Получить посты за конкретный период для всех групп
php artisan vk:posts-get-all --from=2024-01-01 --to=2024-01-31

# С увеличенной задержкой между запросами (для избежания rate limiting)
php artisan vk:posts-get-all --from=2024-01-01 --delay=0.5

# С подробным выводом ошибок
php artisan vk:posts-get-all --from=2024-01-01 --verbose
```

**Примечание:** Команда `vk:posts-get-all` автоматически:
- Читает список групп из `resources/vk-groups.csv`
- Для каждой группы очищает старые посты и загружает новые за указанный период
- Сохраняет посты в базу данных
- Показывает progress bar и итоговую статистику

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

# Анализ с сравнением с предыдущим месяцем и историческими данными подписчиков
php artisan vk:analytics --owner=-12345678 --period=month --compare=previous --use-stats

# Анализ за произвольный период с историческими данными (для более точного ER)
php artisan vk:analytics --owner=-12345678 --period=2024-01-01:2024-01-31 --use-stats

# Анализ с топ-5 постов по ER
php artisan vk:analytics --owner=-12345678 --top=5 --metrics=er

# Анализ с фильтрацией по минимальной вовлеченности (10+ реакций)
php artisan vk:analytics --owner=-12345678 --min-engagement=10

# Экспорт результатов в JSON
php artisan vk:analytics --owner=-12345678 --format=json --output=analytics.json

# Экспорт в CSV (создаст несколько файлов в директории reports/)
php artisan vk:analytics --owner=-12345678 --format=csv --output=reports/analytics.csv

# Полный анализ с всеми опциями и историческими данными
php artisan vk:analytics \
  --owner=-12345678 \
  --period=month \
  --compare=previous \
  --best-time \
  --use-stats \
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

## Поиск постов по слову

```bash
# Поиск слова "концерт" за месяц через API
php artisan vk:word концерт --owner=-12345678 --from=2024-01-01 --to=2024-01-31

# Поиск в базе данных (быстрее)
php artisan vk:word выставка --owner=-12345678 --from=2024-01-01 --db

# Поиск с экспортом в JSON
php artisan vk:word мероприятие --owner=-12345678 --from=2024-01-01 --format=json --output=word_search.json

# Поиск за последний месяц с экспортом в Markdown
php artisan vk:word конференция --owner=-12345678 --from=2024-01-01 --db --format=markdown --output=report.md

# Поиск нескольких слов (по очереди) и сохранение результатов
php artisan vk:word концерт --owner=-12345678 --from=2024-01-01 --db --format=json --output=concert_stats.json
php artisan vk:word выставка --owner=-12345678 --from=2024-01-01 --db --format=json --output=exhibition_stats.json
php artisan vk:word мастер-класс --owner=-12345678 --from=2024-01-01 --db --format=json --output=workshop_stats.json
```

## Статистика сообщества (`stats.get`)

```bash
# Базовая статистика за месяц
php artisan vk:stats-get --group-id=12345678 --from=2025-01-01 --to=2025-01-31

# По месяцам за полгода с демографией
php artisan vk:stats-get --group-id=12345678 --from=2025-09-01 --to=2026-03-01 --interval=month --extended

# Диагностика: вывести сырой ответ VK API
php artisan vk:stats-get --group-id=12345678 --from=2025-09-01 --to=2026-03-01 --interval=month --extended --verbose-raw --format=json

# Сохранить отчет в Markdown
php artisan vk:stats-get --group-id=12345678 --from=2025-09-01 --to=2026-03-01 --interval=month --extended --output=reports/group_stats.md
```

## Ядро лайкнувших пост

```bash
# Базовый расчет ядра (k=1)
php artisan vk:likers-core --owner=-12345678 --post=12345

# Более плотное ядро (минимум 2 связи внутри лайкнувших)
php artisan vk:likers-core --owner=-12345678 --post=12345 --k=2

# Сохранить подробный отчет в JSON
php artisan vk:likers-core --owner=-12345678 --post=12345 --k=2 --format=json --output=reports/likers_core.json

# Сохранить читабельный отчет в Markdown
php artisan vk:likers-core --owner=-12345678 --post=12345 --k=2 --output=reports/likers_core.md
```

**Примеры использования результатов:**
- Анализ популярности тем в группе
- Отслеживание упоминаний конкретных событий
- Поиск контента по ключевым словам для републикации
- Анализ реакции аудитории на определенные темы (сравнение статистики)

## Получение токенов

### Получение user access token

```bash
# Интерактивный режим (команда запросит все параметры)
php artisan vk:token-get-user

# С указанием параметров
php artisan vk:token-get-user --client-id=12345678 --redirect-uri=https://oauth.vk.com/blank.html --scopes=wall,groups,photos,stats
```

### Получение community access token

```bash
# Интерактивный режим
php artisan vk:token-get-group

# С указанием параметров
php artisan vk:token-get-group --client-id=12345678 --client-secret=секретный_ключ --redirect-uri=https://oauth.vk.com/blank.html --scopes=photos,messages
```

## Проверка токена и прав доступа

```bash
# Базовая проверка токена из .env
php artisan vk:token-check

# Проверка нового токена перед использованием
php artisan vk:token-check --token=ваш_новый_токен

# Проверка прав конкретного пользователя
php artisan vk:token-check --user-id=12345678

# Сохранение информации о токене в JSON
php artisan vk:token-check --format=json --output=token_info.json

# Подробная проверка с отладочной информацией
php artisan vk:token-check --verbose

# Проверка токена с выводом в JSON для автоматизации
php artisan vk:token-check --token=ваш_токен --format=json
```

**Примеры интерпретации результатов:**

1. **ER по дням недели** - показывает, в какие дни недели посты получают больше вовлеченности. Используйте это для планирования публикаций.

2. **Лучшее время публикации** - показывает часы с наивысшим средним ER. Рекомендуется публиковать в часы, отмеченные как "⭐ Лучшее" или "⭐ Хорошее".

3. **Топ-посты** - анализируйте топ-посты по разным метрикам, чтобы понять, какой контент работает лучше всего.

4. **Сравнение периодов** - отслеживайте динамику:
   - ⬆️ - рост более 5%
   - ➡️ - стабильно (от -5% до +5%)
   - ⬇️ - падение более 5%

