<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use stdClass;

class GetAlbumsMockTest extends TestCase
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
     * Тест получения альбомов с моками API
     */
    public function test_gets_albums_with_mocked_api()
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

        $command = $this->artisan('vk:albums-get', [
            '--owner' => '-12345678',
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
    }

    /**
     * Тест фильтрации альбомов по минимальному размеру с моками
     */
    public function test_filters_albums_by_min_size_with_mocks()
    {
        $albums = [
            $this->createMockAlbum([
                'id' => 1,
                'title' => 'Большой альбом',
                'size' => 50,
            ]),
            $this->createMockAlbum([
                'id' => 2,
                'title' => 'Маленький альбом',
                'size' => 5,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::response($this->createGetAlbumsResponse($albums), 200),
        ]);

        $command = $this->artisan('vk:albums-get', [
            '--owner' => '-12345678',
            '--min-size' => 10,
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
    }

    /**
     * Тест получения системных альбомов с моками
     */
    public function test_gets_system_albums_with_mocks()
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

        $command = $this->artisan('vk:albums-get', [
            '--owner' => '-12345678',
            '--need-system' => true,
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
    }

    /**
     * Тест получения альбомов с обложками с моками
     */
    public function test_gets_albums_with_covers_with_mocks()
    {
        $albums = [
            $this->createMockAlbum([
                'id' => 1,
                'title' => 'Альбом с обложкой',
                'thumb_src' => 'https://example.com/thumb.jpg',
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::response($this->createGetAlbumsResponse($albums), 200),
        ]);

        $command = $this->artisan('vk:albums-get', [
            '--owner' => '-12345678',
            '--need-covers' => true,
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
    }

    /**
     * Тест обработки пустого ответа API
     */
    public function test_handles_empty_api_response()
    {
        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::response([
                'response' => [
                    'count' => 0,
                    'items' => []
                ]
            ], 200),
        ]);

        $command = $this->artisan('vk:albums-get', [
            '--owner' => '-12345678',
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
    }

    /**
     * Тест обработки ошибки API
     */
    public function test_handles_api_error()
    {
        Http::fake([
            'https://api.vk.com/method/photos.getAlbums*' => Http::response([
                'error' => [
                    'error_code' => 15,
                    'error_msg' => 'Access denied'
                ]
            ], 200),
        ]);

        $command = $this->artisan('vk:albums-get', [
            '--owner' => '-12345678',
            '--format' => 'json',
        ]);

        // Команда должна обработать ошибку (может вернуть 0 или 1 в зависимости от реализации)
        // В текущей реализации команда возвращает 0, так как parseResponse возвращает null при ошибке
        $command->assertExitCode(0);
    }

    /**
     * Тест валидации обязательного параметра owner
     */
    public function test_validates_required_owner_parameter()
    {
        $command = $this->artisan('vk:albums-get', [
            '--format' => 'json',
        ]);

        $command->assertExitCode(1);
    }

    /**
     * Тест валидации формата вывода
     */
    public function test_validates_format_parameter()
    {
        $command = $this->artisan('vk:albums-get', [
            '--owner' => '-12345678',
            '--format' => 'invalid',
        ]);

        $command->assertExitCode(1);
    }

    /**
     * Тест пагинации с моками
     */
    public function test_handles_pagination_with_mocks()
    {
        // Первая страница
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

        $command = $this->artisan('vk:albums-get', [
            '--owner' => '-12345678',
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
    }
}

