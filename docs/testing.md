# Настройка тестов

## Контракт изоляции БД

Тесты, которые создают приложение через `Tests\CreatesApplication` / `Tests\TestCase`,
обязаны использовать только **SQLite `:memory:`** из `tests/.env.testing`.

- Источник `DB_*` — файл `tests/.env.testing` (не `phpunit.xml` и не рабочий `.env`).
- В `phpunit.xml` ключи `DB_CONNECTION` / `DB_DATABASE` намеренно не задаются.
- При загрузке приложения все `DB_*` из testing-файла перекрывают переменные
  окружения процесса (в том числе продовый MySQL из shell).
- `DATABASE_URL` для тестов принудительно очищается.
- Если итоговая конфигурация не SQLite `:memory:`, создание приложения
  завершается ошибкой **до** подключения к БД, миграций и `RefreshDatabase`.
- Защита не распространяется на ad-hoc `php artisan` / скрипты через
  `bootstrap/app.php` без `CreatesApplication` — destructive SQL в них запрещён.

## Файл tests/.env.testing

Обязателен и должен быть читаемым. Минимально:

```env
APP_ENV=testing
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=

DB_CONNECTION=sqlite
DB_DATABASE=:memory:

CACHE_DRIVER=array
VK_TOKEN=test_token_for_unit_tests
VK_API_VERSION=5.122
VK_VERIFY_SSL=false
VK_ACCOUNT_BASE_URL=https://vk.com
```

Файл может быть в `.gitignore`; локальная копия обязательна для запуска тестов.

## Подготовка схемы

Базовый `TestCase` **не** вызывает `migrate:fresh` и не очищает таблицы.
Для работы с БД:

- используйте `RefreshDatabase` в конкретном тестовом классе; или
- поднимайте нужные таблицы вручную в `setUp` (как feature-тесты `vk_posts`).

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyTest extends TestCase
{
    use RefreshDatabase;

    public function test_something_with_database()
    {
        // миграции на SQLite :memory: перед тестом
    }
}
```

## Запуск тестов

```bash
# Все тесты
vendor/bin/phpunit

# Или
php artisan test

# Отдельный файл (предпочтительно при отладке)
vendor/bin/phpunit tests/Feature/PostsFindTest.php
vendor/bin/phpunit tests/Unit/TestDatabaseIsolationTest.php
```

Даже если в shell экспортированы продовые `DB_*`, PHPUnit через `CreatesApplication`
должен остаться на SQLite `:memory:`.

## Рекомендации

1. Моки для внешних API — большинство unit-тестов не требуют БД.
2. `RefreshDatabase` — только где нужна схема/данные.
3. Не запускайте destructive SQL через `bootstrap/app.php` «для отладки тестов».
4. Не используйте рабочую MySQL-базу как тестовую.

## Устранение проблем

### Test database isolation failed

Сообщение значит, что после загрузки конфига подключение не SQLite `:memory:`.
Проверьте:

- существует и читается `tests/.env.testing`;
- в нём `DB_CONNECTION=sqlite` и `DB_DATABASE=:memory:`;
- нет непустого `DATABASE_URL` в итоговой конфигурации;
- не подключён устаревший `bootstrap/cache/config.php` с настройками рабочей БД
  (удалите кеш вручную в рабочем окружении; тесты кеш не перезаписывают).

### Миграции / таблицы отсутствуют

Базовый `TestCase` больше не мигрирует БД сам. Добавьте `RefreshDatabase`
или создайте таблицы в `setUp` теста.
