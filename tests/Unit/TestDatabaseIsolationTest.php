<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\CreatesApplication;

/**
 * Isolation checks avoid connecting to a working MySQL with real credentials.
 */
class TestDatabaseIsolationTest extends TestCase
{
    public function test_forces_sqlite_memory_over_external_mysql_env(): void
    {
        $root = dirname(__DIR__, 2);
        $result = $this->runIsolationScript($root, <<<PHP
putenv('DB_CONNECTION=mysql');
\$_ENV['DB_CONNECTION'] = 'mysql';
\$_SERVER['DB_CONNECTION'] = 'mysql';
putenv('DB_DATABASE=vk_insight_fake');
\$_ENV['DB_DATABASE'] = 'vk_insight_fake';
\$_SERVER['DB_DATABASE'] = 'vk_insight_fake';
putenv('DATABASE_URL=mysql://user:secret@127.0.0.1:3306/vk_insight_fake');
\$_ENV['DATABASE_URL'] = 'mysql://user:secret@127.0.0.1:3306/vk_insight_fake';
\$_SERVER['DATABASE_URL'] = 'mysql://user:secret@127.0.0.1:3306/vk_insight_fake';

\$_SERVER['APP_ENV'] = 'testing';
\$_SERVER['CACHE_DRIVER'] = 'array';
\$_SERVER['VK_TOKEN'] = 'from_phpunit_xml';

require \$ROOT . '/vendor/autoload.php';

\$tester = new class {
    use Tests\\CreatesApplication;

    public function boot()
    {
        return \$this->createApplication();
    }
};

\$app = \$tester->boot();
\$config = \$app['config'];

echo json_encode([
    'default' => \$config->get('database.default'),
    'driver' => \$config->get('database.connections.' . \$config->get('database.default') . '.driver'),
    'database' => \$config->get('database.connections.' . \$config->get('database.default') . '.database'),
    'url' => \$config->get('database.connections.' . \$config->get('database.default') . '.url'),
    'database_url_env' => \$_ENV['DATABASE_URL'] ?? null,
    'vk_token' => env('VK_TOKEN'),
    'app_env' => \$config->get('app.env'),
], JSON_UNESCAPED_SLASHES);
PHP);

        $this->assertSame(0, $result['exit'], $result['stderr'].$result['stdout']);
        $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('sqlite', $payload['default']);
        $this->assertSame('sqlite', $payload['driver']);
        $this->assertSame(':memory:', $payload['database']);
        $this->assertTrue($payload['url'] === null || $payload['url'] === '');
        $this->assertSame('', $payload['database_url_env']);
        $this->assertSame('from_phpunit_xml', $payload['vk_token']);
        $this->assertSame('testing', $payload['app_env']);
    }

    public function test_rejects_missing_env_testing_file(): void
    {
        $harness = new class {
            use CreatesApplication;

            public function load(): void
            {
                $this->loadTestingEnvironment();
            }

            protected function testingEnvironmentPath(): string
            {
                return sys_get_temp_dir().'/vk-utils-missing-env-testing-'.uniqid('', true);
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tests/.env.testing is missing or unreadable');
        $harness->load();
    }

    public function test_rejects_when_resolved_config_is_mysql(): void
    {
        $harness = new class {
            use CreatesApplication;

            public function check(Application $app): void
            {
                $this->assertTestDatabaseIsolation($app);
            }
        };

        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['env' => 'testing'],
            'database' => [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'driver' => 'mysql',
                        'url' => null,
                        'host' => '127.0.0.1',
                        'database' => 'vk_insight_fake',
                        'username' => 'fake',
                        'password' => 'should-not-leak',
                    ],
                ],
            ],
        ]));

        try {
            $harness->check($app);
            $this->fail('Expected isolation failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Test database isolation failed', $e->getMessage());
            $this->assertStringContainsString('sqlite', $e->getMessage());
            $this->assertStringNotContainsString('should-not-leak', $e->getMessage());
            $this->assertStringNotContainsString('vk_insight_fake', $e->getMessage());
            $this->assertStringNotContainsString('fake', $e->getMessage());
        }
    }

    public function test_rejects_nonempty_database_url_in_config(): void
    {
        $harness = new class {
            use CreatesApplication;

            public function check(Application $app): void
            {
                $this->assertTestDatabaseIsolation($app);
            }
        };

        $_ENV['DATABASE_URL'] = '';
        $_SERVER['DATABASE_URL'] = '';
        putenv('DATABASE_URL=');

        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['env' => 'testing'],
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'url' => 'mysql://user:should-not-leak@127.0.0.1/db',
                        'database' => ':memory:',
                    ],
                ],
            ],
        ]));

        try {
            $harness->check($app);
            $this->fail('Expected isolation failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('connection url is set', $e->getMessage());
            $this->assertStringNotContainsString('should-not-leak', $e->getMessage());
        }
    }

    /**
     * @return array{exit:int, stdout:string, stderr:string}
     */
    private function runIsolationScript(string $root, string $phpCode): array
    {
        $file = tempnam(sys_get_temp_dir(), 'vkiso');
        $rootExport = var_export($root, true);
        file_put_contents($file, "<?php\n\$ROOT = {$rootExport};\n".$phpCode);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($file);
        $stdout = [];
        $exit = 0;
        exec($cmd.' 2>'.escapeshellarg($file.'.err'), $stdout, $exit);
        $err = is_file($file.'.err') ? (string) file_get_contents($file.'.err') : '';

        @unlink($file);
        @unlink($file.'.err');

        return [
            'exit' => $exit,
            'stdout' => implode("\n", $stdout),
            'stderr' => $err,
        ];
    }
}
