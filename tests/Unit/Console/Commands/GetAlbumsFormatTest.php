<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use App\Console\Commands\GetAlbums;
use ReflectionClass;
use stdClass;

class GetAlbumsFormatTest extends TestCase
{
    private $command;
    private $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->command = new GetAlbums();
        $this->reflection = new ReflectionClass($this->command);
    }

    /**
     * Создать мок альбома
     */
    private function createAlbum(array $data): stdClass
    {
        $album = new stdClass();
        $album->id = $data['id'] ?? 1;
        $album->title = $data['title'] ?? 'Альбом';
        $album->description = $data['description'] ?? null;
        $album->size = $data['size'] ?? 0;
        $album->created = $data['created'] ?? null;
        $album->updated = $data['updated'] ?? null;
        $album->owner_id = $data['owner_id'] ?? -12345678;
        $album->thumb_id = $data['thumb_id'] ?? 0;
        
        if (isset($data['thumb_src'])) {
            $album->thumb_src = $data['thumb_src'];
        }
        
        return $album;
    }

    /**
     * Получить доступ к приватному методу formatJson
     */
    private function callFormatJson(array $albums): string
    {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod('formatJson');
        $method->setAccessible(true);
        
        return $method->invoke($this->command, $albums);
    }

    /**
     * Получить доступ к приватному методу formatCsv
     */
    private function callFormatCsv(array $albums): string
    {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod('formatCsv');
        $method->setAccessible(true);
        
        return $method->invoke($this->command, $albums);
    }

    /**
     * Тест форматирования в JSON
     */
    public function test_format_json()
    {
        $albums = [
            $this->createAlbum([
                'id' => 1,
                'title' => 'Тестовый альбом',
                'description' => 'Описание альбома',
                'size' => 10,
                'created' => 1672531200, // 2023-01-01 00:00:00
                'updated' => 1672617600, // 2023-01-02 00:00:00
                'owner_id' => -12345678,
                'thumb_id' => 12345,
            ]),
        ];

        $json = $this->callFormatJson($albums);
        
        $this->assertNotEmpty($json);
        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        
        $album = $decoded[0];
        $this->assertEquals(1, $album['id']);
        $this->assertEquals('Тестовый альбом', $album['title']);
        $this->assertEquals('Описание альбома', $album['description']);
        $this->assertEquals(10, $album['size']);
        $this->assertEquals(1672531200, $album['created']);
        $this->assertEquals(1672617600, $album['updated']);
        $this->assertEquals(-12345678, $album['owner_id']);
        $this->assertEquals(12345, $album['thumb_id']);
    }

    /**
     * Тест форматирования в JSON с системным альбомом
     */
    public function test_format_json_with_system_album()
    {
        $albums = [
            $this->createAlbum([
                'id' => -6, // Системный альбом
                'title' => 'Фотографии со мной',
                'description' => null, // У системных альбомов нет описания
                'size' => 5,
                'created' => null, // У системных альбомов нет даты создания
                'updated' => null, // У системных альбомов нет даты обновления
            ]),
        ];

        $json = $this->callFormatJson($albums);
        $decoded = json_decode($json, true);
        
        $this->assertNotNull($decoded);
        $album = $decoded[0];
        $this->assertEquals(-6, $album['id']);
        $this->assertNull($album['description']);
        $this->assertNull($album['created']);
        $this->assertNull($album['updated']);
    }

    /**
     * Тест форматирования в JSON с обложкой
     */
    public function test_format_json_with_cover()
    {
        $albums = [
            $this->createAlbum([
                'id' => 1,
                'title' => 'Альбом с обложкой',
                'thumb_src' => 'https://example.com/thumb.jpg',
            ]),
        ];

        $json = $this->callFormatJson($albums);
        $decoded = json_decode($json, true);
        
        $this->assertNotNull($decoded);
        $album = $decoded[0];
        $this->assertArrayHasKey('thumb_src', $album);
        $this->assertEquals('https://example.com/thumb.jpg', $album['thumb_src']);
    }

    /**
     * Тест форматирования в JSON с несколькими альбомами
     */
    public function test_format_json_multiple_albums()
    {
        $albums = [
            $this->createAlbum(['id' => 1, 'title' => 'Альбом 1']),
            $this->createAlbum(['id' => 2, 'title' => 'Альбом 2']),
            $this->createAlbum(['id' => 3, 'title' => 'Альбом 3']),
        ];

        $json = $this->callFormatJson($albums);
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
        $albums = [
            $this->createAlbum([
                'id' => 1,
                'title' => 'Тестовый альбом',
                'description' => 'Описание',
                'size' => 10,
                'created' => 1672531200,
                'updated' => 1672617600,
                'owner_id' => -12345678,
            ]),
        ];

        $csv = $this->callFormatCsv($albums);
        
        $this->assertNotEmpty($csv);
        $lines = explode("\n", trim($csv));
        $this->assertGreaterThanOrEqual(2, count($lines)); // Заголовок + данные
        
        // Проверяем заголовки
        $headers = str_getcsv($lines[0]);
        $expectedHeaders = ['id', 'title', 'description', 'size', 'created', 'updated', 'owner_id'];
        $this->assertEquals($expectedHeaders, $headers);
        
        // Проверяем данные
        $data = str_getcsv($lines[1]);
        $this->assertEquals('1', $data[0]);
        $this->assertEquals('Тестовый альбом', $data[1]);
        $this->assertEquals('Описание', $data[2]);
        $this->assertEquals('10', $data[3]);
        $this->assertEquals('2023-01-01 00:00:00', $data[4]);
        $this->assertEquals('2023-01-02 00:00:00', $data[5]);
        $this->assertEquals('-12345678', $data[6]);
    }

    /**
     * Тест форматирования в CSV с системным альбомом
     */
    public function test_format_csv_with_system_album()
    {
        $albums = [
            $this->createAlbum([
                'id' => -6,
                'title' => 'Системный альбом',
                'description' => null,
                'created' => null,
                'updated' => null,
            ]),
        ];

        $csv = $this->callFormatCsv($albums);
        $lines = explode("\n", trim($csv));
        
        $data = str_getcsv($lines[1]);
        $this->assertEquals('-6', $data[0]);
        $this->assertEquals('', $data[2]); // Пустое описание
        $this->assertEquals('', $data[4]); // Пустая дата создания
        $this->assertEquals('', $data[5]); // Пустая дата обновления
    }

    /**
     * Тест форматирования в CSV с несколькими альбомами
     */
    public function test_format_csv_multiple_albums()
    {
        $albums = [
            $this->createAlbum(['id' => 1, 'title' => 'Альбом 1']),
            $this->createAlbum(['id' => 2, 'title' => 'Альбом 2']),
            $this->createAlbum(['id' => 3, 'title' => 'Альбом 3']),
        ];

        $csv = $this->callFormatCsv($albums);
        $lines = explode("\n", trim($csv));
        
        // Заголовок + 3 строки данных
        $this->assertGreaterThanOrEqual(4, count($lines));
        
        // Проверяем, что все альбомы присутствуют
        $data1 = str_getcsv($lines[1]);
        $data2 = str_getcsv($lines[2]);
        $data3 = str_getcsv($lines[3]);
        
        $this->assertStringContainsString('Альбом 1', $data1[1]);
        $this->assertStringContainsString('Альбом 2', $data2[1]);
        $this->assertStringContainsString('Альбом 3', $data3[1]);
    }

    /**
     * Тест форматирования в CSV с специальными символами
     */
    public function test_format_csv_with_special_characters()
    {
        $albums = [
            $this->createAlbum([
                'id' => 1,
                'title' => 'Альбом с "кавычками" и, запятыми',
                'description' => 'Описание с\nпереносами',
            ]),
        ];

        $csv = $this->callFormatCsv($albums);
        $lines = explode("\n", trim($csv));
        
        // CSV должен корректно обрабатывать специальные символы
        $data = str_getcsv($lines[1]);
        $this->assertEquals('Альбом с "кавычками" и, запятыми', $data[1]);
    }
}


