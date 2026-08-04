<?php

namespace App\Exceptions\Vk;

/**
 * VK API returned a successful HTTP response, but the JSON structure
 * is missing the expected `response` or `items` keys.
 */
class VkUnexpectedResponseException extends VkException
{
}
