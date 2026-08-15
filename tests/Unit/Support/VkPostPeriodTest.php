<?php

namespace Tests\Unit\Support;

use App\Support\VkPostPeriod;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class VkPostPeriodTest extends TestCase
{
    public function test_date_only_to_is_exclusive_year_boundary(): void
    {
        $period = VkPostPeriod::fromCommandOptions('2025-01-01', '2026-01-01');

        $this->assertTrue($period->containsTimestamp(Carbon::parse('2025-12-31 23:59:59')->timestamp));
        $this->assertFalse($period->containsTimestamp(Carbon::parse('2026-01-01 00:00:00')->timestamp));
        $this->assertFalse($period->containsTimestamp(Carbon::parse('2026-06-01 12:00:00')->timestamp));
    }

    public function test_adjacent_year_periods_do_not_overlap(): void
    {
        $year2025 = VkPostPeriod::fromCommandOptions('2025-01-01', '2026-01-01');
        $year2026 = VkPostPeriod::fromCommandOptions('2026-01-01', '2027-01-01');

        $jan2026 = Carbon::parse('2026-03-15 10:00:00')->timestamp;
        $dec2025 = Carbon::parse('2025-11-20 08:00:00')->timestamp;

        $this->assertTrue($year2025->containsTimestamp($dec2025));
        $this->assertFalse($year2025->containsTimestamp($jan2026));
        $this->assertTrue($year2026->containsTimestamp($jan2026));
        $this->assertFalse($year2026->containsTimestamp($dec2025));
    }

    public function test_optional_bounds_parse_date_only_to_as_exclusive(): void
    {
        $from = VkPostPeriod::fromInclusiveTimestamp('2026-06-01');
        $to = VkPostPeriod::toExclusiveTimestamp('2026-08-01');

        $this->assertSame(Carbon::parse('2026-06-01 00:00:00')->timestamp, $from);
        $this->assertSame(Carbon::parse('2026-08-01 00:00:00')->timestamp, $to);
        $this->assertNull(VkPostPeriod::fromInclusiveTimestamp(null));
        $this->assertNull(VkPostPeriod::toExclusiveTimestamp(''));
    }
}
