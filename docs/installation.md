# Установка

## Требования

- PHP 8.1+ (рекомендуется PHP 8.2+)
- Composer
- MySQL 5.7+ или MariaDB 10.3+ (рекомендуется MySQL 8.0+)
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

5. Создайте базу данных MySQL:
```bash
# Войдите в MySQL
mysql -u root -p

# Создайте базу данных
CREATE DATABASE vk_utils CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Создайте пользователя (опционально)
CREATE USER 'vk_utils_user'@'localhost' IDENTIFIED BY 'ваш_пароль';
GRANT ALL PRIVILEGES ON vk_utils.* TO 'vk_utils_user'@'localhost';
FLUSH PRIVILEGES;
```

6. Настройте переменные окружения в `.env`:
```env
# Настройки базы данных MySQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vk_utils
DB_USERNAME=vk_utils_user
DB_PASSWORD=ваш_пароль

# Настройки VK API
VK_TOKEN=ваш_токен_vk_api
VK_API_VERSION=5.122
VK_VERIFY_SSL=false
VK_ACCOUNT_BASE_URL=https://vk.com
```

7. Запустите миграции:
```bash
php artisan migrate
# или
make migrate
```

8. Создайте файл `resources/vk-groups.csv` со списком групп (или используйте `make vk-groups-file`)

## Запуск тестов

PHPUnit использует только SQLite `:memory:` из `tests/.env.testing` и не должен
обращаться к рабочей MySQL. Подробности и устранение ошибок изоляции —
в [Настройка тестов](testing.md).

```bash
vendor/bin/phpunit
# или
php artisan test
```



