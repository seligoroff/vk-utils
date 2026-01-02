<?php

namespace Tests\Unit\Services\VkApi;

use Tests\TestCase;
use App\Services\VkApi\VkPhotoService;
use Illuminate\Support\Facades\Http;
use stdClass;

class VkPhotoServiceTest extends TestCase
{
    /**
     * Создать мок альбома VK API
     */
    private function createMockAlbum(array $data): stdClass
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
     * Создать мок ответа VK API для photos.getAlbums
     */
    private function createGetAlbumsResponse(array $albums): array
    {
        return [
            'response' => [
                'count' => count($albums),
                'items' => $albums
            ]
        ];
    }

    /**
     * Тест получения альбомов
     */
    public function test_gets_albums()
    {
        $albums = [
            $this->createMockAlbum([
                'id' => 1,
                'title' => 'Альбом 1',
                'size' => 10,
            ]),
            $this->createMockAlbum([
                'id' => 2,
                'title' => 'Альбом 2',
                'size' => 20,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::response($this->createGetAlbumsResponse($albums), 200),
        ]);

        $service = new VkPhotoService();
        $result = $service->getAlbums(-12345678);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals('Альбом 1', $result[0]->title);
    }

    /**
     * Тест получения альбомов с параметрами
     */
    public function test_gets_albums_with_params()
    {
        $albums = [
            $this->createMockAlbum([
                'id' => -6, // Системный альбом
                'title' => 'Фотографии со мной',
                'size' => 5,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::response($this->createGetAlbumsResponse($albums), 200),
        ]);

        $service = new VkPhotoService();
        $result = $service->getAlbums(-12345678, [
            'need_system' => 1,
            'need_covers' => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(-6, $result[0]->id);
        $this->assertEquals('Фотографии со мной', $result[0]->title);
    }

    /**
     * Тест пагинации - получение всех альбомов
     */
    public function test_get_all_albums_with_pagination()
    {
        // Первая страница (100 альбомов)
        $albumsPage1 = [];
        for ($i = 1; $i <= 100; $i++) {
            $albumsPage1[] = $this->createMockAlbum([
                'id' => $i,
                'title' => "Альбом {$i}",
                'size' => $i,
            ]);
        }

        // Вторая страница (меньше 100, значит последняя)
        $albumsPage2 = [
            $this->createMockAlbum([
                'id' => 101,
                'title' => 'Альбом 101',
                'size' => 101,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::sequence()
                ->push($this->createGetAlbumsResponse($albumsPage1), 200)
                ->push($this->createGetAlbumsResponse($albumsPage2), 200),
        ]);

        $service = new VkPhotoService();
        $result = $service->getAllAlbums(-12345678);

        $this->assertIsArray($result);
        $this->assertCount(101, $result); // 100 + 1
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals(101, $result[100]->id);
    }

    /**
     * Тест пагинации - одна страница
     */
    public function test_get_all_albums_single_page()
    {
        $albums = [
            $this->createMockAlbum(['id' => 1, 'title' => 'Альбом 1']),
            $this->createMockAlbum(['id' => 2, 'title' => 'Альбом 2']),
        ];

        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::response($this->createGetAlbumsResponse($albums), 200),
        ]);

        $service = new VkPhotoService();
        $result = $service->getAllAlbums(-12345678);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Тест обработки пустого ответа
     */
    public function test_handles_empty_response()
    {
        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::response([
                'response' => [
                    'count' => 0,
                    'items' => []
                ]
            ], 200),
        ]);

        $service = new VkPhotoService();
        $result = $service->getAllAlbums(-12345678);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Тест обработки null ответа
     */
    public function test_handles_null_response()
    {
        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::response([
                'response' => null
            ], 200),
        ]);

        $service = new VkPhotoService();
        $result = $service->getAlbums(-12345678);

        $this->assertNull($result);
    }

    /**
     * Тест получения альбомов с обложками
     */
    public function test_gets_albums_with_covers()
    {
        $albums = [
            $this->createMockAlbum([
                'id' => 1,
                'title' => 'Альбом с обложкой',
                'thumb_id' => 12345,
                'thumb_src' => 'https://example.com/thumb.jpg',
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::response($this->createGetAlbumsResponse($albums), 200),
        ]);

        $service = new VkPhotoService();
        $result = $service->getAlbums(-12345678, ['need_covers' => 1]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertTrue(isset($result[0]->thumb_src));
        $this->assertEquals('https://example.com/thumb.jpg', $result[0]->thumb_src);
    }
}

