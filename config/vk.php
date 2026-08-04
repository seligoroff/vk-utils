<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VK API Access Token
    |--------------------------------------------------------------------------
    |
    | Your VKontakte API access token
    |
    */

    'token' => env('VK_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    |
    | VK API version to use
    |
    */

    'version' => env('VK_API_VERSION', '5.122'),

    /*
    |--------------------------------------------------------------------------
    | SSL Verification
    |--------------------------------------------------------------------------
    |
    | Enable/disable SSL certificate verification for API requests.
    | Set to false for local XAMPP/WAMP environments without proper CA certificates.
    | Should be true on production.
    |
    */

    'verify_ssl' => env('VK_VERIFY_SSL', false),

    /*
    |--------------------------------------------------------------------------
    | VK Account Base URL
    |--------------------------------------------------------------------------
    |
    | Base URL of your VK account for generating links to posts.
    | Example: 'https://vk.com/seligoroff'
    | Used to build links to posts in browser (not API).
    |
    */

    'account_base_url' => env('VK_ACCOUNT_BASE_URL', 'https://vk.com'),

    /*
    |--------------------------------------------------------------------------
    | Analytics Timezone
    |--------------------------------------------------------------------------
    |
    | Default timezone for analytics commands (vk:analytics).
    | Used for grouping posts by hour and day of week.
    | If not set, falls back to app.timezone (default: UTC).
    |
    | Examples:
    | - Europe/Moscow (UTC+3)
    | - Europe/Kiev (UTC+2)
    | - Asia/Almaty (UTC+6)
    |
    */

    'analytics_timezone' => env('VK_ANALYTICS_TIMEZONE', null),

    /*
    |--------------------------------------------------------------------------
    | API Timeout Configuration
    |--------------------------------------------------------------------------
    |
    | Maximum time (in seconds) for VK API requests.
    | Increase if you encounter timeouts with large wall.get responses.
    |
    */

    'api_timeout' => (float) env('VK_API_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | API Connect Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time (in seconds) to establish connection to VK API.
    |
    */

    'api_connect_timeout' => (float) env('VK_API_CONNECT_TIMEOUT', 10),

];

