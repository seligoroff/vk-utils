<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use stdClass;

class CheckReactionFormatTest extends TestCase
{
    use RefreshDatabase;

    private $testCsvFile;
    private $backupFile;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->testCsvFile = resource_path('vk-groups.csv');
        $this->backupFile = resource_path('vk-groups.csv.backup');
        
        if (file_exists($this->testCsvFile)) {
            copy($this->testCsvFile, $this->backupFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->backupFile)) {
            copy($this->backupFile, $this->testCsvFile);
            unlink($this->backupFile);
        } elseif (file_exists($this->testCsvFile)) {
            unlink($this->testCsvFile);
        }
        
        parent::tearDown();
    }

    private function createTestCsvFile(array $groups): void
    {
        $lines = array_map(function($group) {
            return "https://vk.com/{$group}";
        }, $groups);
        
        file_put_contents($this->testCsvFile, implode("\n", $lines));
    }

    private function createMockPost(array $data): stdClass
    {
        $post = new stdClass();
        $post->id = $data['id'] ?? 1;
        $post->text = $data['text'] ?? '';
        
        $post->likes = new stdClass();
        $post->likes->count = $data['likes'] ?? 0;
        
        $post->reposts = new stdClass();
        $post->reposts->count = $data['reposts'] ?? 0;
        
        return $post;
    }

    private function createResolveResponse(int $objectId): array
    {
        return [
            'response' => [
                'type' => 'group',
                'object_id' => $objectId
            ]
        ];
    }

    private function createGroupByIdResponse(string $name, int $id): array
    {
        return [
            'response' => [
                [
                    'id' => $id,
                    'name' => $name,
                ]
            ]
        ];
    }

    private function createWallGetResponse(array $posts): array
    {
        return [
            'response' => [
                'count' => count($posts),
                'items' => $posts
            ]
        ];
    }

    /**
     * Тест сохранения в JSON файл
     */
    public function test_saves_to_json_file()
    {
        $this->createTestCsvFile(['group1']);

        $post = $this->createMockPost([
            'id' => 123,
            'text' => 'Тестовый пост',
            'likes' => 10,
            'reposts' => 5,
        ]);

        Http::fake([
            'api.vk.com/method/utils.resolveScreenName*' => Http::response(
                $this->createResolveResponse(12345678), 
                200
            ),
            'api.vk.com/method/groups.getById*' => Http::response(
                $this->createGroupByIdResponse('Test Group', 12345678), 
                200
            ),
            'api.vk.com/method/wall.get*' => Http::response(
                $this->createWallGetResponse([$post]), 
                200
            ),
        ]);

        $testFile = storage_path('app/test_check_output_' . uniqid() . '.json');

        $this->artisan('vk:check', [
            '--format' => 'json',
            '--output' => $testFile,
        ])->assertExitCode(0);

        $this->assertFileExists($testFile);
        
        $content = file_get_contents($testFile);
        $this->assertNotEmpty($content);
        
        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
        $this->assertGreaterThan(0, count($decoded));
        
        // Очистка
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }

    /**
     * Тест сохранения в CSV файл
     */
    public function test_saves_to_csv_file()
    {
        $this->createTestCsvFile(['group1']);

        $post = $this->createMockPost([
            'id' => 123,
            'text' => 'Тестовый пост',
            'likes' => 10,
            'reposts' => 5,
        ]);

        Http::fake([
            'api.vk.com/method/utils.resolveScreenName*' => Http::response(
                $this->createResolveResponse(12345678), 
                200
            ),
            'api.vk.com/method/groups.getById*' => Http::response(
                $this->createGroupByIdResponse('Test Group', 12345678), 
                200
            ),
            'api.vk.com/method/wall.get*' => Http::response(
                $this->createWallGetResponse([$post]), 
                200
            ),
        ]);

        $testFile = storage_path('app/test_check_output_' . uniqid() . '.csv');

        $this->artisan('vk:check', [
            '--format' => 'csv',
            '--output' => $testFile,
        ])->assertExitCode(0);

        $this->assertFileExists($testFile);
        
        $content = file_get_contents($testFile);
        $this->assertNotEmpty($content);
        
        // Проверяем наличие BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        
        // Проверяем заголовки
        $content = substr($content, 3); // Убираем BOM
        $lines = explode("\n", trim($content));
        $headers = str_getcsv($lines[0]);
        $this->assertContains('post_text', $headers);
        $this->assertContains('group_name', $headers);
        
        // Очистка
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }

    /**
     * Тест сохранения в Markdown файл
     */
    public function test_saves_to_markdown_file()
    {
        $this->createTestCsvFile(['group1']);

        $post = $this->createMockPost([
            'id' => 123,
            'text' => 'Тестовый пост',
            'likes' => 10,
            'reposts' => 5,
        ]);

        Http::fake([
            'api.vk.com/method/utils.resolveScreenName*' => Http::response(
                $this->createResolveResponse(12345678), 
                200
            ),
            'api.vk.com/method/groups.getById*' => Http::response(
                $this->createGroupByIdResponse('Test Group', 12345678), 
                200
            ),
            'api.vk.com/method/wall.get*' => Http::response(
                $this->createWallGetResponse([$post]), 
                200
            ),
        ]);

        $testFile = storage_path('app/test_check_output_' . uniqid() . '.md');

        $this->artisan('vk:check', [
            '--output' => $testFile, // Автоматически определит Markdown по расширению
        ])->assertExitCode(0);

        $this->assertFileExists($testFile);
        
        $content = file_get_contents($testFile);
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('# Проверка последних постов в группах VK', $content);
        $this->assertStringContainsString('| Post | Group name | Group ID | Likes | Reposts |', $content);
        
        // Очистка
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }

    /**
     * Тест автоматического определения формата по расширению
     */
    public function test_auto_detects_format_by_extension()
    {
        $this->createTestCsvFile(['group1']);

        // Сохраняем кеш в БД для быстрого теста
        DB::table('vk_check_cache')->insert([
            'group_name' => 'Test Group',
            'group_id' => 12345678,
            'post_text' => 'Тестовый пост',
            'likes' => 10,
            'reposts' => 5,
            'cached_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Тест JSON
        $jsonFile = storage_path('app/test_check_' . uniqid() . '.json');
        $this->artisan('vk:check', [
            '--cached' => true,
            '--output' => $jsonFile,
        ])->assertExitCode(0);
        
        $this->assertFileExists($jsonFile);
        $content = file_get_contents($jsonFile);
        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded);
        unlink($jsonFile);

        // Тест CSV
        $csvFile = storage_path('app/test_check_' . uniqid() . '.csv');
        $this->artisan('vk:check', [
            '--cached' => true,
            '--output' => $csvFile,
        ])->assertExitCode(0);
        
        $this->assertFileExists($csvFile);
        $content = file_get_contents($csvFile);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        unlink($csvFile);
    }

    /**
     * Тест валидации формата
     */
    public function test_validates_format_parameter()
    {
        $this->createTestCsvFile(['group1']);

        $this->artisan('vk:check', [
            '--format' => 'invalid_format',
        ])->assertExitCode(1);
    }

    /**
     * Тест использования кеша с экспортом
     */
    public function test_uses_cache_with_export()
    {
        $this->createTestCsvFile(['group1']);

        // Сохраняем кеш в БД
        DB::table('vk_check_cache')->insert([
            'group_name' => 'Test Group',
            'group_id' => 12345678,
            'post_text' => 'Тестовый пост из кеша',
            'likes' => 15,
            'reposts' => 8,
            'cached_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $testFile = storage_path('app/test_check_cache_' . uniqid() . '.json');

        $this->artisan('vk:check', [
            '--cached' => true,
            '--format' => 'json',
            '--output' => $testFile,
        ])->assertExitCode(0);

        $this->assertFileExists($testFile);
        
        $content = file_get_contents($testFile);
        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded);
        $this->assertGreaterThan(0, count($decoded));
        $this->assertEquals('Тестовый пост из кеша', $decoded[0]['post_text']);
        
        // Проверяем, что запросы к API не были сделаны
        Http::assertNothingSent();
        
        // Очистка
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }
}

