<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use App\Console\Commands\GetPosts;
use App\Services\VkApi\VkUrlBuilder;
use ReflectionClass;
use stdClass;

class GetPostsFormatTest extends TestCase
{
    private $command;
    private $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->command = new GetPosts();
        $this->reflection = new ReflectionClass($this->command);
    }

    /**
     * Создать мок поста
     */
    private function createPost(array $data): stdClass
    {
        $post = new stdClass();
        $post->id = $data['id'] ?? 1;
        $post->date = $data['date'] ?? time();
        $post->text = $data['text'] ?? '';
        
        $post->likes = new stdClass();
        $post->likes->count = $data['likes'] ?? 0;
        
        $post->reposts = new stdClass();
        $post->reposts->count = $data['reposts'] ?? 0;
        
        $post->comments = new stdClass();
        $post->comments->count = $data['comments'] ?? 0;
        
        return $post;
    }

    /**
     * Получить доступ к приватному методу formatJson
     */
    private function callFormatJson(array $posts, string $owner = '-12345678'): string
    {
        $command = $this->getMockBuilder(GetPosts::class)
            ->onlyMethods(['option'])
            ->getMock();
        
        $command->expects($this->any())
            ->method('option')
            ->with('owner')
            ->willReturn($owner);
        
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('formatJson');
        $method->setAccessible(true);
        
        return $method->invoke($command, $posts);
    }

    /**
     * Получить доступ к приватному методу formatCsv
     */
    private function callFormatCsv(array $posts, string $owner = '-12345678'): string
    {
        $command = $this->getMockBuilder(GetPosts::class)
            ->onlyMethods(['option'])
            ->getMock();
        
        $command->expects($this->any())
            ->method('option')
            ->with('owner')
            ->willReturn($owner);
        
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('formatCsv');
        $method->setAccessible(true);
        
        return $method->invoke($command, $posts);
    }

    /**
     * Тест форматирования в JSON
     */
    public function test_format_json()
    {
        $posts = [
            $this->createPost([
                'id' => 123,
                'date' => 1672531200, // 2023-01-01 00:00:00
                'text' => 'Тестовый пост',
                'likes' => 10,
                'reposts' => 5,
                'comments' => 3,
            ]),
        ];

        $json = $this->callFormatJson($posts);
        
        $this->assertNotEmpty($json);
        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        
        $post = $decoded[0];
        $this->assertEquals(123, $post['id']);
        $this->assertEquals('2023-01-01 00:00:00', $post['date']);
        $this->assertEquals(1672531200, $post['timestamp']);
        $this->assertEquals('Тестовый пост', $post['text']);
        $this->assertEquals(10, $post['likes']);
        $this->assertEquals(5, $post['reposts']);
        $this->assertEquals(3, $post['comments']);
        $this->assertEquals(0, $post['views']);
        $this->assertStringContainsString('wall-12345678_123', $post['url']);
    }

    /**
     * Тест форматирования в JSON с пустыми значениями
     */
    public function test_format_json_with_empty_values()
    {
        $posts = [
            $this->createPost([
                'id' => 456,
                'date' => 1672531200,
                'text' => '',
                'likes' => 0,
                'reposts' => 0,
                'comments' => 0,
            ]),
        ];

        $json = $this->callFormatJson($posts);
        $decoded = json_decode($json, true);
        
        $this->assertNotNull($decoded);
        $post = $decoded[0];
        $this->assertEquals('', $post['text']);
        $this->assertEquals(0, $post['likes']);
        $this->assertEquals(0, $post['reposts']);
        $this->assertEquals(0, $post['comments']);
    }

    /**
     * Тест форматирования в JSON с несколькими постами
     */
    public function test_format_json_multiple_posts()
    {
        $posts = [
            $this->createPost(['id' => 1, 'text' => 'Пост 1']),
            $this->createPost(['id' => 2, 'text' => 'Пост 2']),
            $this->createPost(['id' => 3, 'text' => 'Пост 3']),
        ];

        $json = $this->callFormatJson($posts);
        $decoded = json_decode($json, true);
        
        $this->assertCount(3, $decoded);
        $this->assertEquals(1, $decoded[0]['id']);
        $this->assertEquals(2, $decoded[1]['id']);
        $this->assertEquals(3, $decoded[2]['id']);
    }

    /**
     * Тест форматирования в CSV
     */
    public function test_format_csv()
    {
        $posts = [
            $this->createPost([
                'id' => 123,
                'date' => 1672531200,
                'text' => 'Тестовый пост',
                'likes' => 10,
                'reposts' => 5,
                'comments' => 3,
            ]),
        ];

        $csv = $this->callFormatCsv($posts);
        
        $this->assertNotEmpty($csv);
        $lines = explode("\n", trim($csv));
        $this->assertGreaterThanOrEqual(2, count($lines)); // Заголовок + данные
        
        // Проверяем заголовки
        $headers = str_getcsv($lines[0]);
        $expectedHeaders = ['date', 'text', 'likes', 'reposts', 'comments', 'views', 'url'];
        $this->assertEquals($expectedHeaders, $headers);
        
        // Проверяем данные
        $data = str_getcsv($lines[1]);
        $this->assertEquals('2023-01-01 00:00:00', $data[0]);
        $this->assertEquals('Тестовый пост', $data[1]);
        $this->assertEquals('10', $data[2]);
        $this->assertEquals('5', $data[3]);
        $this->assertEquals('3', $data[4]);
        $this->assertEquals('0', $data[5]);
        $this->assertStringContainsString('wall-12345678_123', $data[6]);
    }

    /**
     * Тест форматирования в CSV с пустыми значениями
     */
    public function test_format_csv_with_empty_values()
    {
        $posts = [
            $this->createPost([
                'id' => 456,
                'date' => 1672531200,
                'text' => '',
                'likes' => 0,
                'reposts' => 0,
                'comments' => 0,
            ]),
        ];

        $csv = $this->callFormatCsv($posts);
        $lines = explode("\n", trim($csv));
        
        $data = str_getcsv($lines[1]);
        $this->assertEquals('', $data[1]); // Пустой текст
        $this->assertEquals('0', $data[2]); // Лайки
        $this->assertEquals('0', $data[3]); // Репосты
        $this->assertEquals('0', $data[4]); // Комментарии
    }

    /**
     * Тест форматирования в CSV с несколькими постами
     */
    public function test_format_csv_multiple_posts()
    {
        $posts = [
            $this->createPost(['id' => 1, 'text' => 'Пост 1']),
            $this->createPost(['id' => 2, 'text' => 'Пост 2']),
            $this->createPost(['id' => 3, 'text' => 'Пост 3']),
        ];

        $csv = $this->callFormatCsv($posts);
        $lines = explode("\n", trim($csv));
        
        // Заголовок + 3 строки данных
        $this->assertGreaterThanOrEqual(4, count($lines));
        
        // Проверяем, что все посты присутствуют
        $data1 = str_getcsv($lines[1]);
        $data2 = str_getcsv($lines[2]);
        $data3 = str_getcsv($lines[3]);
        
        $this->assertStringContainsString('Пост 1', $data1[1]);
        $this->assertStringContainsString('Пост 2', $data2[1]);
        $this->assertStringContainsString('Пост 3', $data3[1]);
    }

    /**
     * Тест форматирования в CSV с специальными символами
     */
    public function test_format_csv_with_special_characters()
    {
        $posts = [
            $this->createPost([
                'id' => 789,
                'text' => 'Пост с "кавычками" и, запятыми',
            ]),
        ];

        $csv = $this->callFormatCsv($posts);
        $lines = explode("\n", trim($csv));
        
        // CSV должен корректно обрабатывать специальные символы
        $data = str_getcsv($lines[1]);
        $this->assertEquals('Пост с "кавычками" и, запятыми', $data[1]);
    }
}

