<?php

namespace Tests\Unit\Services\VkApi;

use Tests\TestCase;
use App\Services\VkApi\VkGroupService;
use App\Services\VkApi\VkSdkAdapter;
use Mockery;

class VkGroupServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        // Сбрасываем адаптер после каждого теста
        VkGroupService::setAdapter(null);
        parent::tearDown();
    }
    /**
     * Создать мок ответа VK API для groups.getById
     */
    private function createGroupByIdResponse(array $groupData): array
    {
        return [
            'response' => [
                array_merge([
                    'id' => 12345678,
                    'name' => 'Test Group',
                    'screen_name' => 'testgroup',
                ], $groupData)
            ]
        ];
    }

    /**
     * Тест получения информации о группе без дополнительных полей
     */
    public function test_get_by_id_without_fields()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockGroups = Mockery::mock();
        
        $groupData = [
            'id' => 12345678,
            'name' => 'Test Group',
            'screen_name' => 'testgroup',
        ];
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('groups')->andReturn($mockGroups);
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$groupData]);
        
        VkGroupService::setAdapter($mockAdapter);

        $group = VkGroupService::getById(12345678);

        $this->assertNotNull($group);
        $this->assertEquals(12345678, $group->id);
        $this->assertEquals('Test Group', $group->name);
        $this->assertEquals('testgroup', $group->screen_name);
    }

    /**
     * Тест получения информации о группе с полем members_count
     */
    public function test_get_by_id_with_members_count()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $groupData = [
            'id' => 12345678,
            'name' => 'Test Group',
            'screen_name' => 'testgroup',
            'members_count' => 5000,
        ];
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$groupData]);
        
        VkGroupService::setAdapter($mockAdapter);

        $group = VkGroupService::getById(12345678, ['members_count']);

        $this->assertNotNull($group);
        $this->assertEquals(12345678, $group->id);
        $this->assertEquals(5000, $group->members_count);
    }

    /**
     * Тест получения информации о группе с несколькими полями
     */
    public function test_get_by_id_with_multiple_fields()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $groupData = [
            'id' => 12345678,
            'name' => 'Test Group',
            'screen_name' => 'testgroup',
            'members_count' => 5000,
            'description' => 'Test description',
            'status' => 'Active',
        ];
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$groupData]);
        
        VkGroupService::setAdapter($mockAdapter);

        $group = VkGroupService::getById(12345678, ['members_count', 'description', 'status']);

        $this->assertNotNull($group);
        $this->assertEquals(12345678, $group->id);
        $this->assertEquals(5000, $group->members_count);
        $this->assertEquals('Test description', $group->description);
        $this->assertEquals('Active', $group->status);
    }

    /**
     * Тест обратной совместимости - вызов без параметра fields
     */
    public function test_get_by_id_backward_compatibility()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $groupData = [
            'id' => 12345678,
            'name' => 'Test Group',
            'screen_name' => 'testgroup',
        ];
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$groupData]);
        
        VkGroupService::setAdapter($mockAdapter);

        // Вызов без второго параметра (старый способ)
        $group = VkGroupService::getById(12345678);

        $this->assertNotNull($group);
        $this->assertEquals(12345678, $group->id);
    }

    /**
     * Тест валидации полей - пустые строки должны игнорироваться
     */
    public function test_get_by_id_filters_empty_fields()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $groupData = [
            'id' => 12345678,
            'name' => 'Test Group',
            'screen_name' => 'testgroup',
            'members_count' => 5000,
            'description' => 'Test description',
        ];
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$groupData]);
        
        VkGroupService::setAdapter($mockAdapter);

        $group = VkGroupService::getById(12345678, ['members_count', '', '  ', 'description']);

        // Проверяем, что пустые поля были отфильтрованы и запрос прошел успешно
        $this->assertNotNull($group);
        $this->assertEquals(5000, $group->members_count);
        $this->assertEquals('Test description', $group->description);
    }

    /**
     * Тест обработки пустого ответа от API
     */
    public function test_get_by_id_handles_empty_response()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([]);
        
        VkGroupService::setAdapter($mockAdapter);

        $group = VkGroupService::getById(12345678);

        $this->assertNull($group);
    }

    /**
     * Тест обработки ошибки API
     */
    public function test_get_by_id_handles_api_error()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andThrow(new \Exception('VK API Error: One of the parameters specified was missing or invalid', 100));
        
        VkGroupService::setAdapter($mockAdapter);

        $group = VkGroupService::getById(12345678);

        // При ошибке API метод вернет null
        $this->assertNull($group);
    }

    /**
     * Тест получения owner_id для стены группы
     */
    public function test_wall_owner_id_is_negative_for_group()
    {
        $resolved = (object) ['type' => 'group', 'object_id' => 166471];

        $this->assertSame('-166471', VkGroupService::wallOwnerIdFromResolved($resolved));
    }

    /**
     * Тест получения owner_id для стены пользователя
     */
    public function test_wall_owner_id_is_positive_for_user()
    {
        $resolved = (object) ['type' => 'user', 'object_id' => 12345];

        $this->assertSame('12345', VkGroupService::wallOwnerIdFromResolved($resolved));
    }

    /**
     * Тест получения owner_id при отсутствии object_id
     */
    public function test_wall_owner_id_returns_null_without_object_id()
    {
        $resolved = (object) ['type' => 'group'];

        $this->assertNull(VkGroupService::wallOwnerIdFromResolved($resolved));
    }

    /**
     * Тест получения owner_id при нулевом object_id
     */
    public function test_wall_owner_id_returns_null_for_zero_object_id()
    {
        $resolved = (object) ['type' => 'group', 'object_id' => 0];

        $this->assertNull(VkGroupService::wallOwnerIdFromResolved($resolved));
    }

    /**
     * Тест получения owner_id для неизвестного типа (по умолчанию группа)
     */
    public function test_wall_owner_id_treats_unknown_type_as_group()
    {
        $resolved = (object) ['type' => 'page', 'object_id' => 98765];

        $this->assertSame('-98765', VkGroupService::wallOwnerIdFromResolved($resolved));
    }
}

