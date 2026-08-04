<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use App\Exceptions\Vk\VkApiException;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkGroupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use stdClass;

class CheckReactionMockTest extends TestCase
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

        Mockery::close();
        parent::tearDown();
    }

    private function createTestCsvFile(array $groups): void
    {
        $lines = array_map(fn($g) => "https://vk.com/{$g}", $groups);
        file_put_contents($this->testCsvFile, implode("\n", $lines));
    }

    private function mockPost(string $text, int $likes = 10): object
    {
        $post = new stdClass();
        $post->id = 123;
        $post->text = $text;
        $post->likes = new stdClass();
        $post->likes->count = $likes;
        $post->reposts = new stdClass();
        $post->reposts->count = 5;
        $post->comments = new stdClass();
        $post->comments->count = 2;
        return $post;
    }

    private function mockGroupMeta(int $objectId): object
    {
        $meta = new stdClass();
        $meta->type = 'group';
        $meta->object_id = $objectId;
        return $meta;
    }

    private function mockGroupInfo(string $name, int $id): object
    {
        $group = new stdClass();
        $group->id = $id;
        $group->name = $name;
        $group->members_count = 500;
        return $group;
    }

    private function mockGroupServices(): void
    {
        $groupSvc = Mockery::mock('alias:' . VkGroupService::class);
        $groupSvc->shouldReceive('resolveName')
            ->andReturn($this->mockGroupMeta(12345678));
        $groupSvc->shouldReceive('getById')
            ->with(12345678, Mockery::any())
            ->andReturn($this->mockGroupInfo('Test Group', 12345678));
        $groupSvc->shouldReceive('wallOwnerIdFromResolved')
            ->andReturn('-12345678');
    }

    // ────────────────────────────────────────────────────────

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_checks_reactions_with_mocks(): void
    {
        $this->createTestCsvFile(['group1']);
        $this->mockGroupServices();

        $mock = Mockery::mock('overload:' . VkWallService::class)->makePartial();
        $mock->shouldReceive('getPosts')->andReturn([$this->mockPost('Test post', 10)]);
        $mock->shouldReceive('setOwner')->andReturnSelf();

        $this->artisan('vk:check')->assertExitCode(0);
    }

    public function test_uses_cache_when_available(): void
    {
        $this->createTestCsvFile(['group1']);

        DB::table('vk_check_cache')->insert([
            'group_name'    => 'Test Group',
            'group_id'      => 12345678,
            'post_text'     => 'Cached post',
            'likes'         => 10,
            'reposts'       => 5,
            'members_count' => 500,
            'post_date'     => time(),
            'cached_at'     => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->artisan('vk:check', ['--cached' => true])->assertExitCode(0);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handles_group_without_text_posts(): void
    {
        $this->createTestCsvFile(['group1']);
        $this->mockGroupServices();

        $mock = Mockery::mock('overload:' . VkWallService::class)->makePartial();
        $mock->shouldReceive('getPosts')->andReturn([$this->mockPost('', 5)]);
        $mock->shouldReceive('setOwner')->andReturnSelf();

        $this->artisan('vk:check')->assertExitCode(0);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handles_empty_wall_response(): void
    {
        $this->createTestCsvFile(['group1']);
        $this->mockGroupServices();

        $mock = Mockery::mock('overload:' . VkWallService::class)->makePartial();
        $mock->shouldReceive('getPosts')->andReturn([]);
        $mock->shouldReceive('setOwner')->andReturnSelf();

        $this->artisan('vk:check')->assertExitCode(0);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handles_api_error(): void
    {
        $this->createTestCsvFile(['group1']);

        $groupSvc = Mockery::mock('alias:' . VkGroupService::class);
        $groupSvc->shouldReceive('resolveName')
            ->andReturn($this->mockGroupMeta(12345678));
        $groupSvc->shouldReceive('getById')
            ->with(12345678, Mockery::any())
            ->andReturn($this->mockGroupInfo('Test Group', 12345678));
        $groupSvc->shouldReceive('wallOwnerIdFromResolved')
            ->andReturn('-12345678');

        $mock = Mockery::mock('overload:' . VkWallService::class)->makePartial();
        $mock->shouldReceive('getPosts')
            ->andThrow(new VkApiException('Access denied', VkApiException::REASON_ACCESS_DENIED, 15));
        $mock->shouldReceive('setOwner')->andReturnSelf();

        $this->artisan('vk:check')->assertExitCode(1);
    }
}
