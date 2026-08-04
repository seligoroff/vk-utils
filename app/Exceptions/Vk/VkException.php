<?php

namespace App\Exceptions\Vk;

/**
 * Base exception for VK API errors.
 *
 * All domain VK exceptions extend this class so that consumers
 * can catch a single type when the specific reason does not matter.
 */
abstract class VkException extends \RuntimeException
{
}
