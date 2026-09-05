<?php

namespace App\Services\VkApi;

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

    /**
     * Create a new VK SDK adapter instance
     */
    public function __construct()
    {
        $this->version = config('vk.version', '5.131');
        $this->client = new VKApiClient($this->version);
        $this->token = config('vk.token', '');
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
     * @return \VK\Api\Wall
     */
    public function wall()
    {
        return $this->client->wall();
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
     * @param callable $callback Function that makes SDK call
     * @param string|null $context Context for error messages (e.g., "getting wall posts")
     * @param array{retry?:bool,max_attempts?:int,wait?:bool} $options
     * @return mixed
     * @throws VkRequestException
     */
    public function execute(callable $callback, ?string $context = null, array $options = []): mixed
    {
        if (VkApiGuard::blocked()) {
            throw VkApiGuard::blockedException($context);
        }

        $retry = (bool) ($options['retry'] ?? false);
        $maxAttempts = $retry ? max(1, (int) ($options['max_attempts'] ?? 3)) : 1;
        $wait = array_key_exists('wait', $options) ? (bool) $options['wait'] : true;

        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                return $callback();
            } catch (\Throwable $e) {
                $err = $this->withContext(VkErrorClassifier::fromThrowable($e), $context);

                if ($err->stopsRun) {
                    VkApiGuard::block($err, $this->token);
                    throw $err;
                }

                if ($retry && $err->retryable && $attempt < $maxAttempts) {
                    if ($wait) {
                        usleep($this->backoffMicroseconds($attempt));
                    }
                    continue;
                }

                throw $err;
            }
        }

        throw new VkRequestException(
            'VK API request failed',
            VkRequestException::CATEGORY_API,
            null,
            false,
            false
        );
    }

    private function withContext(VkRequestException $err, ?string $context): VkRequestException
    {
        if ($context === null || $context === '') {
            return $err;
        }

        $suffix = " ({$context})";
        if (str_contains($err->getMessage(), $suffix)) {
            return $err;
        }

        return new VkRequestException(
            $err->getMessage().$suffix,
            $err->category,
            $err->vkCode,
            $err->retryable,
            $err->stopsRun,
            $err
        );
    }

    private function backoffMicroseconds(int $failedAttempt): int
    {
        $base = 0.4 * (2 ** ($failedAttempt - 1));
        $jitter = 0.8 + (mt_rand(0, 40) / 100);
        $seconds = min(2.0, $base * $jitter);

        return (int) round($seconds * 1_000_000);
    }
}

