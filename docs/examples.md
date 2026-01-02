# Примеры использования

## Ежедневная проверка активности в группах

```bash
# Первый запуск - сбор данных
php artisan vk:check --delay=0.5

# Повторный просмотр без запросов к API
php artisan vk:check --cached
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

# Сохранить в базу данных
php artisan vk:posts-get --owner=-12345678 --from=2024-01-01 --to=2024-01-31 --db

# Фильтрация постов с минимум 10 лайками
php artisan vk:posts-get --owner=-12345678 --from=2024-01-01 --min-likes=10
```

