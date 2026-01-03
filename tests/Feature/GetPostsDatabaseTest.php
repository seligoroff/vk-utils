<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use stdClass;

class GetPostsDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Создать мок поста VK API
     */
    private function createMockPost(array $data): stdClass
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
     * Создать мок ответа VK API для wall.get
     */
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
     * Тест сохранения постов в БД
     */
    public function test_saves_posts_to_database()
    {
        $posts = [
            $this->createMockPost([
                'id' => 123,
                'date' => 1672531200, // 2023-01-01 00:00:00
                'text' => 'Тестовый пост 1',
                'likes' => 10,
                'reposts' => 5,
                'comments' => 3,
            ]),
            $this->createMockPost([
                'id' => 124,
                'date' => 1672617600, // 2023-01-02 00:00:00
                'text' => 'Тестовый пост 2',
                'likes' => 20,
                'reposts' => 10,
                'comments' => 5,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response($this->createWallGetResponse($posts), 200),
        ]);

        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-03',
            '--db' => true,
        ])->assertExitCode(0);

        // Проверяем, что посты сохранены в БД
        $this->assertDatabaseCount('vk_posts', 2);
        
        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 123,
            'owner_id' => '-12345678',
            'text' => 'Тестовый пост 1',
            'likes' => 10,
            'reposts' => 5,
            'comments' => 3,
        ]);

        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 124,
            'owner_id' => '-12345678',
            'text' => 'Тестовый пост 2',
            'likes' => 20,
            'reposts' => 10,
            'comments' => 5,
        ]);
    }

    /**
     * Тест предотвращения дублей при сохранении
     */
    public function test_prevents_duplicates_on_save()
    {
        $posts = [
            $this->createMockPost([
                'id' => 123,
                'date' => 1672531200,
                'text' => 'Тестовый пост',
                'likes' => 10,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response($this->createWallGetResponse($posts), 200),
        ]);

        // Первое сохранение
        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('vk_posts', 1);

        // Второе сохранение того же поста
        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        // Должен остаться только один пост (дубль пропущен)
        $this->assertDatabaseCount('vk_posts', 1);
    }

    /**
     * Тест сохранения постов с пустым текстом
     */
    public function test_saves_posts_with_empty_text()
    {
        $posts = [
            $this->createMockPost([
                'id' => 125,
                'date' => 1672531200,
                'text' => '', // Пустой текст
                'likes' => 5,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response($this->createWallGetResponse($posts), 200),
        ]);

        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        $post = DB::table('vk_posts')->where('post_id', 125)->first();
        $this->assertNotNull($post);
        $this->assertEquals('-12345678', $post->owner_id);
        $this->assertEmpty($post->text); // Пустая строка или null
        $this->assertEquals(5, $post->likes);
    }

    /**
     * Тест сохранения постов с нулевыми значениями
     */
    public function test_saves_posts_with_zero_values()
    {
        $posts = [
            $this->createMockPost([
                'id' => 126,
                'date' => 1672531200,
                'text' => 'Пост',
                'likes' => 0,
                'reposts' => 0,
                'comments' => 0,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response($this->createWallGetResponse($posts), 200),
        ]);

        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 126,
            'owner_id' => '-12345678',
            'likes' => 0,
            'reposts' => 0,
            'comments' => 0,
        ]);
    }

    /**
     * Тест сохранения URL поста
     */
    public function test_saves_post_url()
    {
        $posts = [
            $this->createMockPost([
                'id' => 127,
                'date' => 1672531200,
                'text' => 'Пост',
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response($this->createWallGetResponse($posts), 200),
        ]);

        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        $post = DB::table('vk_posts')->where('post_id', 127)->first();
        $this->assertNotNull($post);
        $this->assertStringContainsString('wall-12345678_127', $post->url);
    }

    /**
     * Тест обработки пустого списка постов
     */
    public function test_handles_empty_posts_list()
    {
        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response([
                'response' => [
                    'count' => 0,
                    'items' => []
                ]
            ], 200),
        ]);

        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        // БД должна остаться пустой
        $this->assertDatabaseCount('vk_posts', 0);
    }

    /**
     * Тест очистки постов только указанного владельца (--clear)
     */
    public function test_clear_removes_only_specified_owner_posts()
    {
        // Сначала создаем посты для двух разных владельцев
        $posts1 = [
            $this->createMockPost([
                'id' => 100,
                'date' => 1672531200,
                'text' => 'Пост владельца 1',
                'likes' => 10,
            ]),
        ];

        $posts2 = [
            $this->createMockPost([
                'id' => 200,
                'date' => 1672531200,
                'text' => 'Пост владельца 2',
                'likes' => 20,
            ]),
        ];

        // Сохраняем посты первого владельца
        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::sequence()
                ->push($this->createWallGetResponse($posts1), 200)
                ->push($this->createWallGetResponse($posts2), 200)
                ->push($this->createWallGetResponse([
                    $this->createMockPost([
                        'id' => 101,
                        'date' => 1672531200,
                        'text' => 'Новый пост владельца 1',
                        'likes' => 15,
                    ]),
                ]), 200),
        ]);

        $this->artisan('vk:posts-get', [
            '--owner' => '-11111111',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        // Сохраняем посты второго владельца
        $this->artisan('vk:posts-get', [
            '--owner' => '-22222222',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        // Проверяем, что оба поста сохранены
        $this->assertDatabaseCount('vk_posts', 2);
        $this->assertDatabaseHas('vk_posts', ['owner_id' => '-11111111', 'post_id' => 100]);
        $this->assertDatabaseHas('vk_posts', ['owner_id' => '-22222222', 'post_id' => 200]);

        // Очищаем посты только первого владельца
        $this->artisan('vk:posts-get', [
            '--owner' => '-11111111',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
            '--clear' => true,
        ])->assertExitCode(0);

        // Проверяем, что пост первого владельца удален и заменен новым
        // А пост второго владельца остался нетронутым
        $this->assertDatabaseCount('vk_posts', 2);
        $this->assertDatabaseHas('vk_posts', ['owner_id' => '-11111111', 'post_id' => 101]);
        $this->assertDatabaseMissing('vk_posts', ['owner_id' => '-11111111', 'post_id' => 100]);
        $this->assertDatabaseHas('vk_posts', ['owner_id' => '-22222222', 'post_id' => 200]);
    }

    /**
     * Тест обновления существующих записей (--update)
     */
    public function test_update_updates_existing_posts()
    {
        $posts = [
            $this->createMockPost([
                'id' => 300,
                'date' => 1672531200,
                'text' => 'Исходный пост',
                'likes' => 10,
                'reposts' => 5,
                'comments' => 3,
            ]),
        ];

        $updatedPosts = [
            $this->createMockPost([
                'id' => 300,
                'date' => 1672531200,
                'text' => 'Обновленный пост',
                'likes' => 25,
                'reposts' => 10,
                'comments' => 7,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::sequence()
                ->push($this->createWallGetResponse($posts), 200)
                ->push($this->createWallGetResponse($updatedPosts), 200),
        ]);

        // Первое сохранение
        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 300,
            'owner_id' => '-12345678',
            'text' => 'Исходный пост',
            'likes' => 10,
            'reposts' => 5,
            'comments' => 3,
        ]);

        // Обновляем пост с новыми данными
        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
            '--update' => true,
        ])->assertExitCode(0);

        // Проверяем, что запись обновлена, а не создана дубликат
        $this->assertDatabaseCount('vk_posts', 1);
        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 300,
            'owner_id' => '-12345678',
            'text' => 'Обновленный пост',
            'likes' => 25,
            'reposts' => 10,
            'comments' => 7,
        ]);
    }

    /**
     * Тест обновления существующих и добавления новых постов (--update)
     */
    public function test_update_adds_new_and_updates_existing_posts()
    {
        $initialPosts = [
            $this->createMockPost([
                'id' => 400,
                'date' => 1672531200,
                'text' => 'Существующий пост',
                'likes' => 10,
            ]),
        ];

        $updatedPosts = [
            $this->createMockPost([
                'id' => 400,
                'date' => 1672531200,
                'text' => 'Обновленный существующий пост',
                'likes' => 20,
            ]),
            $this->createMockPost([
                'id' => 401,
                'date' => 1672531200,
                'text' => 'Новый пост',
                'likes' => 15,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::sequence()
                ->push($this->createWallGetResponse($initialPosts), 200)
                ->push($this->createWallGetResponse($updatedPosts), 200),
        ]);

        // Первое сохранение
        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        // Обновляем существующий и добавляем новый
        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
            '--update' => true,
        ])->assertExitCode(0);

        // Проверяем, что оба поста есть в БД
        $this->assertDatabaseCount('vk_posts', 2);
        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 400,
            'text' => 'Обновленный существующий пост',
            'likes' => 20,
        ]);
        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 401,
            'text' => 'Новый пост',
            'likes' => 15,
        ]);
    }
}

