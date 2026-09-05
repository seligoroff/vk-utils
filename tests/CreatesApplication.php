<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $this->loadTestingEnvironment();

        $app = require __DIR__.'/../bootstrap/app.php';

        // Конфиг до провайдеров: отказ до любого SQL, в т.ч. RefreshDatabase.
        $app->bootstrapWith([
            LoadEnvironmentVariables::class,
        ]);

        // Корневой .env / .env.testing мог снова выставить DB_* или DATABASE_URL.
        $this->loadTestingEnvironment();

        $app->bootstrapWith([
            LoadConfiguration::class,
        ]);

        $this->assertTestDatabaseIsolation($app);

        $app->bootstrapWith([
            HandleExceptions::class,
            RegisterFacades::class,
            SetRequestForConsole::class,
            RegisterProviders::class,
            BootProviders::class,
        ]);

        return $app;
    }

    /**
     * Load tests/.env.testing before creating the Laravel application.
     * DB_* always override the process environment; other keys yield to phpunit.xml ($_SERVER).
     */
    protected function loadTestingEnvironment(): void
    {
        $envTestingPath = $this->testingEnvironmentPath();

        if (! is_readable($envTestingPath)) {
            throw new RuntimeException(
                'Required file tests/.env.testing is missing or unreadable. '.
                'Tests must use SQLite :memory: from that file.'
            );
        }

        $parsed = $this->parseEnvFile($envTestingPath);

        if (($parsed['DB_CONNECTION'] ?? null) !== 'sqlite') {
            throw new RuntimeException(
                'tests/.env.testing must set DB_CONNECTION=sqlite (isolation from working MySQL).'
            );
        }

        if (($parsed['DB_DATABASE'] ?? null) !== ':memory:') {
            throw new RuntimeException(
                'tests/.env.testing must set DB_DATABASE=:memory: (isolation from working MySQL).'
            );
        }

        foreach ($parsed as $key => $value) {
            if ($this->isDatabaseEnvKey($key)) {
                $this->setEnvValue($key, $value);
                continue;
            }

            // Приоритет phpunit.xml: ключи уже в $_SERVER не перебиваем.
            if (array_key_exists($key, $_SERVER)) {
                continue;
            }

            $this->setEnvValue($key, $value);
        }

        // DATABASE_URL из рабочего .env не должен выбирать MySQL поверх DB_*.
        $this->setEnvValue('DATABASE_URL', '');

        if (! array_key_exists('APP_ENV', $_SERVER)) {
            $this->setEnvValue('APP_ENV', 'testing');
        }
    }

    protected function testingEnvironmentPath(): string
    {
        return __DIR__.'/.env.testing';
    }

    /**
     * @return array<string, string>
     */
    protected function parseEnvFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Unable to read {$path}");
        }

        $parsed = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $value = str_replace(['\\n', '\\r'], ["\n", "\r"], $value);
            $parsed[$key] = $value;
        }

        return $parsed;
    }

    protected function isDatabaseEnvKey(string $key): bool
    {
        return $key === 'DATABASE_URL' || str_starts_with($key, 'DB_');
    }

    protected function setEnvValue(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }

    /**
     * Fail before providers/SQL if the resolved DB config is not SQLite :memory:.
     */
    protected function assertTestDatabaseIsolation(Application $app): void
    {
        $config = $app['config'];
        $default = (string) $config->get('database.default');
        $connection = (array) $config->get("database.connections.{$default}", []);
        $driver = (string) ($connection['driver'] ?? '');
        $database = (string) ($connection['database'] ?? '');
        $url = $connection['url'] ?? null;

        $envUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if ($envUrl === false) {
            $envUrl = '';
        }

        $problems = [];

        if ($default !== 'sqlite') {
            $problems[] = 'database.default is not [sqlite]';
        }

        if ($driver !== 'sqlite') {
            $problems[] = 'driver is not [sqlite]';
        }

        if ($database !== ':memory:') {
            $problems[] = 'database is not [:memory:]';
        }

        if (is_string($url) && $url !== '') {
            $problems[] = 'connection url is set (must be empty for tests)';
        }

        if (is_string($envUrl) && $envUrl !== '') {
            $problems[] = 'DATABASE_URL is set (must be empty for tests)';
        }

        $appEnv = (string) $config->get('app.env', '');
        if ($appEnv !== 'testing') {
            $problems[] = 'app.env is not [testing]';
        }

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(
            "Test database isolation failed; expected SQLite :memory: from tests/.env.testing. ".
            implode('; ', $problems)
        );
    }
}
