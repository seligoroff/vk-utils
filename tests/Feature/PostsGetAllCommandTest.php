<?php

namespace Tests\Feature;

use Tests\TestCase;
use Mockery;

class PostsGetAllCommandTest extends TestCase
{
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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_domain_exception_per_group_returns_nonzero(): void
    {
        file_put_contents($this->testCsvFile, 'https://vk.com/testgroup');

        // Mock VkGroupService static methods via alias
        $meta = new \stdClass();
        $meta->type = 'group';
        $meta->object_id = 12345678;

        $groupSvc = Mockery::mock('alias:App\Services\VkApi\VkGroupService');
        $groupSvc->shouldReceive('resolveName')->andReturn($meta);
        $groupSvc->shouldReceive('wallOwnerIdFromResolved')
            ->with($meta)->andReturn('-12345678');

        // Mock VkWallService — always throws
        $wallMock = Mockery::mock('overload:App\Services\VkApi\VkWallService')->makePartial();
        $wallMock->shouldReceive('getPosts')
            ->andThrow(new \App\Exceptions\Vk\VkApiException(
                'Доступ к стене запрещён.',
                \App\Exceptions\Vk\VkApiException::REASON_ACCESS_DENIED,
                15
            ));
        $wallMock->shouldReceive('setOwner')->andReturnSelf();

        $this->artisan('vk:posts-get-all', [
            '--from' => '2026-06-01',
            '--to'   => '2026-07-01',
        ])->assertExitCode(1);
    }
}
