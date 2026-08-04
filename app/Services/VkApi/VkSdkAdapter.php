<?php

namespace App\Services\VkApi;

use VK\Actions\Wall;
use VK\Client\VKApiClient;

/**
 * Adapter for VK PHP SDK
 * 
 * Provides a unified interface for working with VK API through the official SDK.
 * This adapter simplifies SDK usage and provides centralized configuration.
 * 
 * @package App\Services\VkApi
 */
class VkSdkAdapter
{
    private VKApiClient $client;
    private string $token;
    private string $version;
    private ?VkHttpClientFactory $httpClientFactory = null;
    private ?Wall $wallInstance = null;

    /**
     * Create a new VK SDK adapter instance
     */
    public function __construct()
    {
        $this->version = config('vk.version', '5.131');
        $this->client = new VKApiClient($this->version);
        $this->token = config('vk.token', '');
        $this->httpClientFactory = new VkHttpClientFactory();
    }

    /**
     * Set a custom HTTP client factory (for testing).
     */
    public function setHttpClientFactory(?VkHttpClientFactory $factory): void
    {
        $this->httpClientFactory = $factory;
        $this->wallInstance = null;
    }

    /**
     * Get VK API version
     * 
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Get access token
     * 
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Get wall API methods
     *
     * Uses a dedicated VKApiRequest with configured timeouts
     * instead of the default SDK client (which hardcodes 10 s).
     *
     * @return \VK\Actions\Wall
     */
    public function wall(): Wall
    {
        if ($this->wallInstance === null && $this->httpClientFactory !== null) {
            $request = $this->httpClientFactory->createRequest(
                $this->version,
                null // language — use default
            );
            $this->wallInstance = new Wall($request);
        }

        // Fallback to default client if factory was explicitly set to null
        return $this->wallInstance ?? $this->client->wall();
    }

    /**
     * Get groups API methods
     * 
     * @return \VK\Api\Groups
     */
    public function groups()
    {
        return $this->client->groups();
    }

    /**
     * Get friends API methods
     *
     * @return \VK\Api\Friends
     */
    public function friends()
    {
        return $this->client->friends();
    }

    /**
     * Get likes API methods
     *
     * @return \VK\Api\Likes
     */
    public function likes()
    {
        return $this->client->likes();
    }

    /**
     * Get photos API methods
     * 
     * @return \VK\Api\Photos
     */
    public function photos()
    {
        return $this->client->photos();
    }

    /**
     * Get audio API methods
     * 
     * Note: VK PHP SDK does not support audio API, so this method will throw an exception.
     * Use VkApiClient directly for audio methods if needed.
     * 
     * @return \VK\Api\Audio
     * @throws \Exception
     */
    public function audio()
    {
        try {
            return $this->client->audio();
        } catch (\TypeError $e) {
            // SDK не поддерживает audio API - выбрасываем понятное исключение
            throw new \Exception('Audio API is not supported by VK PHP SDK. Please use VkApiClient directly or make HTTP requests.', 0, $e);
        } catch (\Exception $e) {
            throw new \Exception('Failed to access audio API: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get users API methods
     * 
     * @return \VK\Api\Users
     */
    public function users()
    {
        return $this->client->users();
    }

    /**
     * Get utils API methods
     * 
     * @return \VK\Api\Utils
     */
    public function utils()
    {
        return $this->client->utils();
    }

    /**
     * Get stats API methods
     * 
     * @return \VK\Api\Stats
     */
    public function stats()
    {
        return $this->client->stats();
    }

    /**
     * Get account API methods
     * 
     * @return \VK\Api\Account
     */
    public function account()
    {
        return $this->client->account();
    }

    /**
     * Get the underlying VKApiClient instance
     * 
     * @return VKApiClient
     */
    public function getClient(): VKApiClient
    {
        return $this->client;
    }

    /**
     * Execute a method with error handling
     * 
     * This is a helper method that wraps SDK calls with common error handling.
     * It converts SDK exceptions into more user-friendly exceptions.
     * 
     * @param callable $callback Function that makes SDK call
     * @param string|null $context Context for error messages (e.g., "getting wall posts")
     * @return mixed
     * @throws \Exception
     */
    public function execute(callable $callback, ?string $context = null)
    {
        try {
            return $callback();
        } catch (\VK\Exceptions\VKApiException $e) {
            $context = $context ? " ($context)" : '';
            throw new \Exception("VK API Error{$context}: " . $e->getMessage(), $e->getCode(), $e);
        } catch (\VK\Exceptions\VKClientException $e) {
            $context = $context ? " ($context)" : '';
            throw new \Exception("VK Client Error{$context}: " . $e->getMessage(), $e->getCode(), $e);
        } catch (\Exception $e) {
            throw $e;
        }
    }
}

