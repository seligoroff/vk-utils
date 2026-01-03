<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use App\Console\Commands\CheckReaction;
use ReflectionClass;

class CheckReactionFormatTest extends TestCase
{
    private $command;
    private $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->command = new CheckReaction();
        $this->reflection = new ReflectionClass($this->command);
    }

    /**
     * Получить доступ к приватному методу formatJson
     */
    private function callFormatJson(array $data): string
    {
        $method = $this->reflection->getMethod('formatJson');
        $method->setAccessible(true);
        
        return $method->invoke($this->command, $data);
    }

    /**
     * Получить доступ к приватному методу formatCsv
     */
    private function callFormatCsv(array $data): string
    {
        $method = $this->reflection->getMethod('formatCsv');
        $method->setAccessible(true);
        
        return $method->invoke($this->command, $data);
    }

    /**
     * Получить доступ к приватному методу formatMarkdown
     */
    private function callFormatMarkdown(array $data): string
    {
        $method = $this->reflection->getMethod('formatMarkdown');
        $method->setAccessible(true);
        
        return $method->invoke($this->command, $data);
    }

    /**
     * Создать тестовые данные
     */
    private function createTestData(): array
    {
        return [
            [
                'post_text' => 'Тестовый пост с текстом',
                'group_name' => 'Test Group',
                'group_id' => 12345678,
                'likes' => 10,
                'reposts' => 5,
            ],
            [
                'post_text' => 'Другой пост',
                'group_name' => 'Another Group',
                'group_id' => 87654321,
                'likes' => 20,
                'reposts' => 3,
            ],
        ];
    }

    /**
     * Тест форматирования в JSON
     */
    public function test_format_json()
    {
        $data = $this->createTestData();
        
        $json = $this->callFormatJson($data);
        
        $this->assertNotEmpty($json);
        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        
        $first = $decoded[0];
        $this->assertEquals('Тестовый пост с текстом', $first['post_text']);
        $this->assertEquals('Test Group', $first['group_name']);
        $this->assertEquals(12345678, $first['group_id']);
        $this->assertEquals(10, $first['likes']);
        $this->assertEquals(5, $first['reposts']);
    }

    /**
     * Тест форматирования в JSON с пустыми данными
     */
    public function test_format_json_empty_data()
    {
        $json = $this->callFormatJson([]);
        
        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
        $this->assertEmpty($decoded);
    }

    /**
     * Тест форматирования в CSV
     */
    public function test_format_csv()
    {
        $data = $this->createTestData();
        
        $csv = $this->callFormatCsv($data);
        
        $this->assertNotEmpty($csv);
        
        // Проверяем наличие BOM для корректного отображения кириллицы
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        
        // Убираем BOM для парсинга
        $csv = substr($csv, 3);
        
        $lines = explode("\n", trim($csv));
        $this->assertGreaterThanOrEqual(3, count($lines)); // Заголовок + 2 строки данных
        
        // Проверяем заголовки
        $headers = str_getcsv($lines[0]);
        $expectedHeaders = ['post_text', 'group_name', 'group_id', 'likes', 'reposts'];
        $this->assertEquals($expectedHeaders, $headers);
        
        // Проверяем данные первой строки
        $dataRow = str_getcsv($lines[1]);
        $this->assertEquals('Тестовый пост с текстом', $dataRow[0]);
        $this->assertEquals('Test Group', $dataRow[1]);
        $this->assertEquals('12345678', $dataRow[2]);
        $this->assertEquals('10', $dataRow[3]);
        $this->assertEquals('5', $dataRow[4]);
    }

    /**
     * Тест форматирования в CSV с пустыми данными
     */
    public function test_format_csv_empty_data()
    {
        $csv = $this->callFormatCsv([]);
        
        $this->assertNotEmpty($csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        
        $csv = substr($csv, 3);
        $lines = explode("\n", trim($csv));
        
        // Должен быть только заголовок
        $this->assertCount(1, $lines);
        $headers = str_getcsv($lines[0]);
        $this->assertEquals(['post_text', 'group_name', 'group_id', 'likes', 'reposts'], $headers);
    }

    /**
     * Тест форматирования в CSV с специальными символами
     */
    public function test_format_csv_with_special_characters()
    {
        $data = [
            [
                'post_text' => 'Пост с "кавычками" и, запятыми',
                'group_name' => 'Group, with commas',
                'group_id' => 12345678,
                'likes' => 10,
                'reposts' => 5,
            ],
        ];
        
        $csv = $this->callFormatCsv($data);
        $csv = substr($csv, 3); // Убираем BOM
        
        $lines = explode("\n", trim($csv));
        $dataRow = str_getcsv($lines[1]);
        
        // CSV должен корректно обрабатывать специальные символы
        $this->assertEquals('Пост с "кавычками" и, запятыми', $dataRow[0]);
        $this->assertEquals('Group, with commas', $dataRow[1]);
    }

    /**
     * Тест форматирования в Markdown
     */
    public function test_format_markdown()
    {
        $data = $this->createTestData();
        
        $markdown = $this->callFormatMarkdown($data);
        
        $this->assertNotEmpty($markdown);
        $this->assertStringContainsString('# Проверка последних постов в группах VK', $markdown);
        $this->assertStringContainsString('## Результаты', $markdown);
        $this->assertStringContainsString('| Post | Group name | Group ID | Likes | Reposts |', $markdown);
        $this->assertStringContainsString('Тестовый пост с текстом', $markdown);
        $this->assertStringContainsString('Test Group', $markdown);
        $this->assertStringContainsString('**Всего групп:** 2', $markdown);
    }

    /**
     * Тест форматирования в Markdown с пустыми данными
     */
    public function test_format_markdown_empty_data()
    {
        $markdown = $this->callFormatMarkdown([]);
        
        $this->assertNotEmpty($markdown);
        $this->assertStringContainsString('# Проверка последних постов в группах VK', $markdown);
        $this->assertStringContainsString('Нет данных для отображения', $markdown);
    }

    /**
     * Тест форматирования в Markdown с экранированием символов
     */
    public function test_format_markdown_escapes_special_characters()
    {
        $data = [
            [
                'post_text' => "Пост с | символом\nи переносами строк",
                'group_name' => 'Group | Name',
                'group_id' => 12345678,
                'likes' => 10,
                'reposts' => 5,
            ],
        ];
        
        $markdown = $this->callFormatMarkdown($data);
        
        // Проверяем, что символы | экранированы в тексте поста
        $this->assertStringContainsString('\\|', $markdown);
        // Проверяем, что переносы строк в тексте поста убраны (текст должен быть в одной строке таблицы)
        // Ищем строку таблицы с нашим текстом
        $lines = explode("\n", $markdown);
        foreach ($lines as $line) {
            if (strpos($line, 'Пост с') !== false) {
                // В строке таблицы не должно быть переносов строк внутри ячейки
                $this->assertStringNotContainsString("\n", $line);
                break;
            }
        }
    }

    /**
     * Тест форматирования в Markdown с длинным текстом
     */
    public function test_format_markdown_truncates_long_text()
    {
        $longText = str_repeat('Очень длинный текст поста. ', 20); // ~600 символов
        $data = [
            [
                'post_text' => $longText,
                'group_name' => 'Test Group',
                'group_id' => 12345678,
                'likes' => 10,
                'reposts' => 5,
            ],
        ];
        
        $markdown = $this->callFormatMarkdown($data);
        
        // Текст должен быть обрезан до 50 символов
        $this->assertStringContainsString('Очень длинный текст поста', $markdown);
        // Но не должен содержать весь длинный текст
        $this->assertLessThan(strlen($longText), strlen($markdown));
    }
}

