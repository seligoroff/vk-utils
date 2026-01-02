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

];

