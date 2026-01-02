<?php

namespace App\Services\VkApi;

use Illuminate\Support\Facades\Http;

/**
 * Base VK API client
 * Handles HTTP transport and authentication
 */
class VkApiClient
{
    const HOST = 'https://api.vk.com';
    
    /**
     * Get configured HTTP client for VK API requests
     * SSL verification can be controlled via VK_VERIFY_SSL in .env
     * 
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected static function httpClient()
    {
        return Http::withOptions([
            'verify' => config('vk.verify_ssl', false)
        ]);
    }
    
    /**
     * Make GET request to VK API with automatic token and version injection
     * 
     * @param string $method VK API method name (e.g., 'wall.get')
     * @param array $params Additional parameters for the request
     * @return \Illuminate\Http\Client\Response
     */
    protected static function apiGet(string $method, array $params = [])
    {
        return self::httpClient()->get(self::HOST . '/method/' . $method, array_merge([
            'access_token' => config('vk.token'),
            'v' => config('vk.version')
        ], $params));
    }
    
    /**
     * Parse VK API response and extract data
     * Returns objects to maintain backward compatibility with old code
     * 
     * @param \Illuminate\Http\Client\Response $response
     * @return mixed|null
     */
    protected static function parseResponse($response)
    {
        $result = json_decode($response->body());
        return $result->response ?? null;
    }
}

