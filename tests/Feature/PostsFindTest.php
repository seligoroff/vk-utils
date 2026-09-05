<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostsFindTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('vk_posts')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2024_01_01_000000_create_vk_posts_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasColumn('vk_posts', 'views')) {
            Schema::table('vk_posts', function ($table) {
                $table->integer('views')->nullable();
            });
        }

        DB::table('vk_posts')->delete();
    }

    public function test_finds_by_db_id(): void
    {
        $id = $this->insertPost('-100', 10, '2026-07-01 12:00:00', 'Целевой пост');
        $this->insertPost('-100', 11, '2026-07-02 12:00:00', 'Другой пост');

        $exitCode = Artisan::call('vk:posts-find', [
            '--db-id' => $id,
            '--format' => 'json',
        ]);

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame($id, $result['posts'][0]['id']);
        $this->assertSame('Целевой пост', $result['posts'][0]['text']);
    }

    public function test_finds_by_owner_and_post_id(): void
    {
        $this->insertPost('-2507736', 25182, '2026-03-03 10:00:00', 'нужный');
        $this->insertPost('-999', 25182, '2026-03-03 10:00:00', 'другая стена');

        $exitCode = Artisan::call('vk:posts-find', [
            '--owner' => '-2507736',
            '--post-id' => 25182,
            '--format' => 'json',
        ]);

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame('-2507736', $result['posts'][0]['owner_id']);
        $this->assertSame(25182, $result['posts'][0]['post_id']);
    }

    public function test_post_id_without_owner_can_return_multiple_owners(): void
    {
        $this->insertPost('-1', 100, '2026-03-03 10:00:00', 'A');
        $this->insertPost('-2', 100, '2026-03-04 10:00:00', 'B');

        $exitCode = Artisan::call('vk:posts-find', [
            '--post-id' => 100,
            '--format' => 'json',
        ]);

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $result['pagination']['total']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('Уточните --owner', $result['warnings'][0]);
    }

    public function test_text_is_case_insensitive_and_supports_cyrillic(): void
    {
        $this->insertPost('-100', 1, '2026-07-01 12:00:00', 'Памятник героям');
        $this->insertPost('-100', 2, '2026-07-01 13:00:00', 'Другой текст');

        $exitCode = Artisan::call('vk:posts-find', [
            '--owner' => '-100',
            '--text' => 'памятник',
            '--format' => 'json',
        ]);

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame(1, $result['posts'][0]['post_id']);
    }

    public function test_text_treats_percent_and_underscore_literally(): void
    {
        $this->insertPost('-100', 1, '2026-07-01 12:00:00', 'скидка 50% на всё');
        $this->insertPost('-100', 2, '2026-07-01 13:00:00', 'скидка 50X на всё');
        $this->insertPost('-100', 3, '2026-07-01 14:00:00', 'имя_фамилия');
        $this->insertPost('-100', 4, '2026-07-01 15:00:00', 'имяXфамилия');

        $percent = Artisan::call('vk:posts-find', [
            '--owner' => '-100',
            '--text' => '50%',
            '--format' => 'json',
        ]);
        $percentResult = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $percent);
        $this->assertSame(1, $percentResult['pagination']['total']);
        $this->assertSame(1, $percentResult['posts'][0]['post_id']);

        $underscore = Artisan::call('vk:posts-find', [
            '--owner' => '-100',
            '--text' => 'имя_фамилия',
            '--format' => 'json',
        ]);
        $underscoreResult = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $underscore);
        $this->assertSame(1, $underscoreResult['pagination']['total']);
        $this->assertSame(3, $underscoreResult['posts'][0]['post_id']);
    }

    public function test_combines_filters_with_and_and_exclusive_to(): void
    {
        $this->insertPost('-100', 1, '2026-07-01 12:00:00', 'чемпионскую премию выдали');
        $this->insertPost('-100', 2, '2026-08-01 00:00:00', 'чемпионскую премию снова');
        $this->insertPost('-100', 3, '2026-07-15 12:00:00', 'без нужной фразы');

        $exitCode = Artisan::call('vk:posts-find', [
            '--owner' => '-100',
            '--text' => 'чемпионскую премию',
            '--from' => '2026-07-01',
            '--to' => '2026-08-01',
            '--format' => 'json',
        ]);

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame(1, $result['posts'][0]['post_id']);
    }

    public function test_requires_selective_filter(): void
    {
        $exitCode = Artisan::call('vk:posts-find', [
            '--owner' => '-100',
            '--from' => '2026-07-01',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('селективный фильтр', Artisan::output());
    }

    public function test_empty_result_is_success(): void
    {
        $exitCode = Artisan::call('vk:posts-find', [
            '--owner' => '-100',
            '--post-id' => 999,
            '--format' => 'json',
        ]);

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $result['pagination']['total']);
        $this->assertSame([], $result['posts']);
    }

    private function insertPost(string $ownerId, int $postId, string $date, string $text): int
    {
        $timestamp = Carbon::parse($date)->timestamp;

        return (int) DB::table('vk_posts')->insertGetId([
            'post_id' => $postId,
            'owner_id' => $ownerId,
            'timestamp' => $timestamp,
            'date' => Carbon::createFromTimestamp($timestamp)->toDateTimeString(),
            'text' => $text,
            'likes' => $postId,
            'reposts' => $postId,
            'comments' => $postId,
            'views' => $postId * 10,
            'url' => "https://vk.com/wall{$ownerId}_{$postId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
