<?php

namespace App\Exceptions\Vk;

/**
 * Network-level failure when communicating with VK API.
 *
 * The `reason` field distinguishes transient errors (eligible for retry)
 * from permanent ones.
 */
class VkTransportException extends VkException
{
    public const REASON_TIMEOUT    = 'timeout';
    public const REASON_CONNECTION = 'connection';
    public const REASON_OTHER      = 'other';

    private string $reason;

    public function __construct(
        string $message,
        string $reason = self::REASON_OTHER,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
