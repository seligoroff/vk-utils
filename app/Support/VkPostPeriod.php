<?php

namespace App\Support;

use Carbon\Carbon;
use Exception;

/**
 * Half-open period [fromInclusive, toExclusive) for vk:posts-get and related commands.
 *
 * For date-only --to=YYYY-MM-DD the day is exclusive: --from=2025-01-01 --to=2026-01-01 = весь 2025 год.
 * Adjacent periods do not overlap: 2025→2026-01-01 and 2026-01-01→2027-01-01.
 */
class VkPostPeriod
{
    public function __construct(
        public readonly int $fromInclusive,
        public readonly int $toExclusive,
    ) {
    }

    public function containsTimestamp(int $timestamp): bool
    {
        return $timestamp >= $this->fromInclusive && $timestamp < $this->toExclusive;
    }

    public function fromLabel(): string
    {
        return Carbon::createFromTimestamp($this->fromInclusive)->format('Y-m-d H:i:s');
    }

    /**
     * Inclusive end of period for human-readable messages.
     */
    public function toInclusiveLabel(): string
    {
        return Carbon::createFromTimestamp($this->toExclusive - 1)->format('Y-m-d H:i:s');
    }

    public static function fromCommandOptions(string $from, ?string $to = null): self
    {
        $fromInclusive = self::parseFromBoundary($from);
        $toExclusive = $to !== null
            ? self::parseToExclusiveBoundary($to)
            : Carbon::now()->timestamp + 1;

        if ($fromInclusive >= $toExclusive) {
            throw new Exception('Дата начала периода должна быть раньше даты окончания');
        }

        return new self($fromInclusive, $toExclusive);
    }

    private static function parseFromBoundary(string $dateString): int
    {
        $dateString = trim(strtolower($dateString));

        $relativeDates = [
            'today' => Carbon::today(),
            'yesterday' => Carbon::yesterday(),
            'last week' => Carbon::now()->subWeek()->startOfDay(),
            'last month' => Carbon::now()->subMonth()->startOfDay(),
        ];

        if (isset($relativeDates[$dateString])) {
            $carbon = $relativeDates[$dateString];
            if (!$carbon instanceof Carbon) {
                $carbon = Carbon::parse($dateString);
            }

            return $carbon->startOfDay()->timestamp;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return Carbon::createFromFormat('Y-m-d', $dateString)->startOfDay()->timestamp;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateString)) {
            return Carbon::createFromFormat('Y-m-d H:i:s', $dateString)->timestamp;
        }

        return Carbon::parse($dateString)->timestamp;
    }

    private static function parseToExclusiveBoundary(string $dateString): int
    {
        $dateString = trim(strtolower($dateString));

        if ($dateString === 'today') {
            return Carbon::tomorrow()->startOfDay()->timestamp;
        }

        if ($dateString === 'yesterday') {
            return Carbon::today()->startOfDay()->timestamp;
        }

        if ($dateString === 'last week') {
            return Carbon::now()->subWeek()->startOfDay()->timestamp;
        }

        if ($dateString === 'last month') {
            return Carbon::now()->subMonth()->startOfDay()->timestamp;
        }

        // Date-only: exclusive upper bound at start of that day
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return Carbon::createFromFormat('Y-m-d', $dateString)->startOfDay()->timestamp;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateString)) {
            return Carbon::createFromFormat('Y-m-d H:i:s', $dateString)->timestamp + 1;
        }

        return Carbon::parse($dateString)->timestamp + 1;
    }
}
