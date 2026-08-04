<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\CoreTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class CoreTransitionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('user_post_segments')) {
            Schema::create('user_post_segments', function ($table) {
                $table->bigInteger('user_id');
                $table->string('owner_id', 32);
                $table->integer('post_id');
                $table->string('segment', 16);
                $table->integer('friends_in_likers_count')->default(0);
                $table->timestamps();
                $table->unique(['owner_id', 'post_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('vk_posts')) {
            Schema::create('vk_posts', function ($table) {
                $table->id();
                $table->integer('post_id');
                $table->string('owner_id', 32);
                $table->timestamp('date');
                $table->text('text')->nullable();
                $table->integer('likes')->default(0);
                $table->integer('reposts')->default(0);
                $table->integer('comments')->default(0);
                $table->integer('views')->default(0);
                $table->string('url')->nullable();
                $table->timestamps();
            });
        }

        DB::table('user_post_segments')->delete();
        DB::table('vk_posts')->delete();
    }

    public function test_build_transitions_single_pair(): void
    {
        // Post 1: user 1=core, user 2=open
        // Post 2: user 1=core, user 2=core (recruited!), user 3=open
        $this->seedSegments('-670335', 1, [
            ['user_id' => 1, 'segment' => 'core'],
            ['user_id' => 2, 'segment' => 'open'],
        ]);
        $this->seedSegments('-670335', 2, [
            ['user_id' => 1, 'segment' => 'core'],
            ['user_id' => 2, 'segment' => 'core'],
            ['user_id' => 3, 'segment' => 'open'],
        ]);

        $posts = $this->buildPostsMap('-670335');
        $postIds = [1, 2];

        $cmd = new CoreTransitions();
        $method = new ReflectionMethod(CoreTransitions::class, 'buildTransitions');
        $t = $method->invoke($cmd, $posts, $postIds);

        $this->assertEquals(1, $t['core_to_core'], 'user 1 stayed in core');
        $this->assertEquals(1, $t['open_to_core'], 'user 2 recruited to core');
        $this->assertEquals(1, $t['absent_to_open'], 'user 3 is new');
        $this->assertEquals(0, $t['core_to_open'], 'no core to open');
    }

    public function test_stable_core_min_2_of_3(): void
    {
        // 3 posts: user 1 core-core-core (3×), user 2 core-open-core (2×), user 3 core-open-open (1×)
        $this->seedSegments('-670335', 1, [
            ['user_id' => 1, 'segment' => 'core'],
            ['user_id' => 2, 'segment' => 'core'],
            ['user_id' => 3, 'segment' => 'core'],
        ]);
        $this->seedSegments('-670335', 2, [
            ['user_id' => 1, 'segment' => 'core'],
            ['user_id' => 2, 'segment' => 'open'],
            ['user_id' => 3, 'segment' => 'open'],
        ]);
        $this->seedSegments('-670335', 3, [
            ['user_id' => 1, 'segment' => 'core'],
            ['user_id' => 2, 'segment' => 'core'],
            ['user_id' => 3, 'segment' => 'open'],
        ]);

        $posts = $this->buildPostsMap('-670335');
        $postIds = [1, 2, 3];

        $cmd = new CoreTransitions();
        $method = new ReflectionMethod(CoreTransitions::class, 'buildStableCore');
        $result = $method->invoke($cmd, $posts, $postIds, 3, 2);

        $this->assertCount(1, $result, 'one window for 3 posts with size 3');
        $this->assertEquals(3, $result[0]['last_post_id']);
        $this->assertEquals(2, $result[0]['stable_core_count'], 'users 1 and 2 have >=2 core appearances');
    }

    public function test_stable_core_empty_when_no_one_meets_threshold(): void
    {
        $this->seedSegments('-670335', 1, [
            ['user_id' => 1, 'segment' => 'core'],
        ]);
        $this->seedSegments('-670335', 2, [
            ['user_id' => 1, 'segment' => 'open'],
        ]);

        $posts = $this->buildPostsMap('-670335');
        $postIds = [1, 2];

        $cmd = new CoreTransitions();
        $method = new ReflectionMethod(CoreTransitions::class, 'buildStableCore');
        $result = $method->invoke($cmd, $posts, $postIds, 2, 2);

        $this->assertCount(1, $result);
        $this->assertEquals(0, $result[0]['stable_core_count'], 'no user meets min 2 core in window');
    }

    private function seedSegments(string $ownerId, int $postId, array $users): void
    {
        foreach ($users as $u) {
            DB::table('user_post_segments')->insert([
                'user_id' => $u['user_id'],
                'owner_id' => $ownerId,
                'post_id' => $postId,
                'segment' => $u['segment'],
                'friends_in_likers_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('vk_posts')->insert([
            'post_id' => $postId,
            'owner_id' => $ownerId,
            'date' => now()->addDays($postId),
            'text' => "post {$postId}",
            'likes' => count($users),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buildPostsMap(string $ownerId): array
    {
        $rows = DB::table('user_post_segments as s')
            ->join('vk_posts as p', function ($join) use ($ownerId) {
                $join->on('s.owner_id', '=', 'p.owner_id')
                     ->on('s.post_id', '=', 'p.post_id');
            })
            ->where('s.owner_id', $ownerId)
            ->select('s.user_id', 's.post_id', 's.segment', 'p.date')
            ->orderBy('p.date')
            ->get();

        $posts = [];
        foreach ($rows as $row) {
            $posts[$row->post_id]['date'] = $row->date;
            $posts[$row->post_id]['users'][$row->user_id] = $row->segment;
        }
        return $posts;
    }
}
