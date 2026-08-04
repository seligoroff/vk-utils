<?php

namespace Tests\Unit\Services\VkApi;

use Tests\TestCase;
use App\Services\VkApi\VkHttpClientFactory;
use VK\Client\VKApiRequest;
use ReflectionProperty;

class VkHttpClientFactoryTest extends TestCase
{
    private function getClientConfig(VKApiRequest $request): array
    {
        $prop = new ReflectionProperty(VKApiRequest::class, 'client');
        $prop->setAccessible(true);
        $client = $prop->getValue($request);

        return $client ? $client->getConfig() : [];
    }

    public function test_timeout_from_config_is_used(): void
    {
        config(['vk.api_timeout' => 45]);
        config(['vk.api_connect_timeout' => 12]);
        config(['vk.verify_ssl' => true]);

        $factory = new VkHttpClientFactory();
        $request = $factory->createRequest('5.131');

        $this->assertInstanceOf(VKApiRequest::class, $request);

        $config = $this->getClientConfig($request);
        $this->assertEquals(45.0, $config['timeout']);
        $this->assertEquals(12.0, $config['connect_timeout']);
        $this->assertTrue($config['verify']);
    }

    public function test_default_timeouts_when_config_missing(): void
    {
        config(['vk.api_timeout' => null]);
        config(['vk.api_connect_timeout' => null]);

        $factory = new VkHttpClientFactory();
        $request = $factory->createRequest('5.131');

        $this->assertInstanceOf(VKApiRequest::class, $request);

        $config = $this->getClientConfig($request);
        // null config → default via ?: operator
        $this->assertEquals(30.0, $config['timeout']);
        $this->assertEquals(10.0, $config['connect_timeout']);
    }
}
