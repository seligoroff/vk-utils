<?php

namespace App\Services\VkApi;

use VK\Client\VKApiRequest;
use VK\Transport\Client;

/**
 * Builds a VKApiRequest with HTTP timeouts from project configuration.
 *
 * This factory is the single place where Guzzle client options are
 * assembled, making timeout behaviour consistent and testable.
 */
class VkHttpClientFactory
{
    /**
     * Create a VKApiRequest instance with configured timeouts.
     *
     * @param string $apiVersion VK API version string (e.g. "5.131")
     * @param string|null $language
     * @return VKApiRequest
     */
    public function createRequest(
        string $apiVersion,
        ?string $language = null
    ): VKApiRequest {
        $httpClient = new Client([
            'base_uri'        => 'https://api.vk.com/method',
            'timeout'         => (float) (config('vk.api_timeout') ?: 30),
            'connect_timeout' => (float) (config('vk.api_connect_timeout') ?: 10),
            'verify'          => (bool) (config('vk.verify_ssl') ?: false),
        ]);

        return new VKApiRequest(
            $apiVersion,
            $language,
            'https://api.vk.com/method',
            $httpClient
        );
    }
}
