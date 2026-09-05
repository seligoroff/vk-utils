<?php

namespace App\Services\VkApi;

class VkApiGuard
{
    private static bool $blocked = false;
    private static ?string $category = null;
    private static ?int $vkCode = null;
    private static ?string $tokenFingerprint = null;

    public static function block(VkRequestException $e, ?string $token = null): void
    {
        self::$blocked = true;
        self::$category = $e->category;
        self::$vkCode = $e->vkCode;

        if (is_string($token) && $token !== '') {
            self::$tokenFingerprint = substr(hash('sha256', $token), 0, 8);
        }
    }

    public static function blocked(): bool
    {
        return self::$blocked;
    }

    public static function blockedException(?string $context = null): VkRequestException
    {
        $message = 'VK API cooldown is active';
        if ($context) {
            $message .= " ({$context})";
        }

        return new VkRequestException(
            $message,
            self::$category ?? VkRequestException::CATEGORY_FLOOD,
            self::$vkCode,
            false,
            true
        );
    }

    public static function reset(): void
    {
        self::$blocked = false;
        self::$category = null;
        self::$vkCode = null;
        self::$tokenFingerprint = null;
    }
}
