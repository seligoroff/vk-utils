# Установка

## Требования

- PHP 8.1+ (рекомендуется PHP 8.2+)
- Composer
- Токен доступа VK API

## Быстрая установка (с Makefile)

Если у вас установлен `make`, используйте команду для полной настройки:

```bash
make setup
```

Эта команда автоматически выполнит:
- Установку зависимостей Composer
- Генерацию ключа приложения
- Запуск миграций базы данных
- Создание файла `resources/vk-groups.csv`

## Ручная установка

1. Клонируйте репозиторий или скачайте проект

2. Установите зависимости:
```bash
composer install
# или
make install
```

3. Скопируйте файл `.env.example` в `.env` (если его нет):
```bash
cp .env.example .env
```

4. Сгенерируйте ключ приложения:
```bash
php artisan key:generate
# или
make key-generate
```

5. Запустите миграции:
```bash
php artisan migrate
# или
make migrate
```

6. Настройте переменные окружения в `.env`:
```env
VK_TOKEN=ваш_токен_vk_api
VK_API_VERSION=5.122
VK_VERIFY_SSL=false
VK_ACCOUNT_BASE_URL=https://vk.com
```

7. Создайте файл `resources/vk-groups.csv` со списком групп (или используйте `make vk-groups-file`)

