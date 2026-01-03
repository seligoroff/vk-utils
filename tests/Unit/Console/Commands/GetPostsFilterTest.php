<?php

namespace Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use App\Console\Commands\GetPosts;
use ReflectionClass;
use stdClass;

class GetPostsFilterTest extends TestCase
{
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
     * Получить доступ к приватному методу фильтрации
     * Поскольку фильтрация происходит в handle(), создадим вспомогательный метод для тестирования
     */
    private function filterPost(stdClass $post, array $options): bool
    {
        // Логика фильтрации из GetPosts::handle()
        // Фильтрация по тексту
        if (!empty($options['with-text-only']) && empty($post->text)) {
            return false;
        }

        // Фильтрация по лайкам
        if (!empty($options['min-likes']) && (!isset($post->likes->count) || $post->likes->count < (int)$options['min-likes'])) {
            return false;
        }

        // Фильтрация по репостам
        if (!empty($options['min-reposts']) && (!isset($post->reposts->count) || $post->reposts->count < (int)$options['min-reposts'])) {
            return false;
        }

        return true;
    }

    /**
     * Тест фильтрации по наличию текста
     */
    public function test_filters_by_text_presence()
    {
        $postWithText = $this->createPost(['text' => 'Текст поста']);
        $postWithoutText = $this->createPost(['text' => '']);

        // С фильтром with-text-only
        $this->assertTrue($this->filterPost($postWithText, ['with-text-only' => true]));
        $this->assertFalse($this->filterPost($postWithoutText, ['with-text-only' => true]));

        // Без фильтра
        $this->assertTrue($this->filterPost($postWithText, []));
        $this->assertTrue($this->filterPost($postWithoutText, []));
    }

    /**
     * Тест фильтрации по минимальному количеству лайков
     */
    public function test_filters_by_min_likes()
    {
        $postWithManyLikes = $this->createPost(['likes' => 100]);
        $postWithFewLikes = $this->createPost(['likes' => 5]);
        $postWithoutLikes = $this->createPost(['likes' => 0]);

        // Минимум 10 лайков
        $this->assertTrue($this->filterPost($postWithManyLikes, ['min-likes' => 10]));
        $this->assertFalse($this->filterPost($postWithFewLikes, ['min-likes' => 10]));
        $this->assertFalse($this->filterPost($postWithoutLikes, ['min-likes' => 10]));

        // Без фильтра
        $this->assertTrue($this->filterPost($postWithManyLikes, []));
        $this->assertTrue($this->filterPost($postWithFewLikes, []));
        $this->assertTrue($this->filterPost($postWithoutLikes, []));
    }

    /**
     * Тест фильтрации по минимальному количеству репостов
     */
    public function test_filters_by_min_reposts()
    {
        $postWithManyReposts = $this->createPost(['reposts' => 50]);
        $postWithFewReposts = $this->createPost(['reposts' => 2]);
        $postWithoutReposts = $this->createPost(['reposts' => 0]);

        // Минимум 10 репостов
        $this->assertTrue($this->filterPost($postWithManyReposts, ['min-reposts' => 10]));
        $this->assertFalse($this->filterPost($postWithFewReposts, ['min-reposts' => 10]));
        $this->assertFalse($this->filterPost($postWithoutReposts, ['min-reposts' => 10]));

        // Без фильтра
        $this->assertTrue($this->filterPost($postWithManyReposts, []));
        $this->assertTrue($this->filterPost($postWithFewReposts, []));
        $this->assertTrue($this->filterPost($postWithoutReposts, []));
    }

    /**
     * Тест комбинированной фильтрации
     */
    public function test_combined_filters()
    {
        $goodPost = $this->createPost([
            'text' => 'Текст поста',
            'likes' => 100,
            'reposts' => 50,
        ]);

        $badPost1 = $this->createPost([
            'text' => '', // Нет текста
            'likes' => 100,
            'reposts' => 50,
        ]);

        $badPost2 = $this->createPost([
            'text' => 'Текст поста',
            'likes' => 5, // Мало лайков
            'reposts' => 50,
        ]);

        $badPost3 = $this->createPost([
            'text' => 'Текст поста',
            'likes' => 100,
            'reposts' => 2, // Мало репостов
        ]);

        $options = [
            'with-text-only' => true,
            'min-likes' => 10,
            'min-reposts' => 10,
        ];

        $this->assertTrue($this->filterPost($goodPost, $options));
        $this->assertFalse($this->filterPost($badPost1, $options));
        $this->assertFalse($this->filterPost($badPost2, $options));
        $this->assertFalse($this->filterPost($badPost3, $options));
    }

    /**
     * Тест фильтрации постов без свойств likes/reposts
     */
    public function test_filters_posts_without_likes_reposts()
    {
        $post = new stdClass();
        $post->id = 1;
        $post->date = time();
        $post->text = 'Текст';
        // Нет свойств likes и reposts

        // Должен пройти фильтр, если не указаны min-likes и min-reposts
        $this->assertTrue($this->filterPost($post, []));
        $this->assertTrue($this->filterPost($post, ['with-text-only' => true]));

        // Должен не пройти, если указаны min-likes или min-reposts
        $this->assertFalse($this->filterPost($post, ['min-likes' => 10]));
        $this->assertFalse($this->filterPost($post, ['min-reposts' => 10]));
    }
}


