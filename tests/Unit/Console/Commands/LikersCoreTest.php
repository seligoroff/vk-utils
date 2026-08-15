<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\LikersCore;
use ReflectionMethod;
use Tests\TestCase;

class LikersCoreTest extends TestCase
{
    public function test_resolve_profile_fields_with_demographics(): void
    {
        $fields = $this->invoke('resolveProfileFields', [true]);

        $this->assertSame(['screen_name', 'bdate', 'sex'], $fields);
    }

    public function test_resolve_profile_fields_without_demographics(): void
    {
        $fields = $this->invoke('resolveProfileFields', [false]);

        $this->assertSame(['screen_name'], $fields);
    }

    public function test_build_sample_coverage_for_truncated_sample(): void
    {
        $coverage = $this->invoke('buildSampleCoverage', [332, 300]);

        $this->assertSame(332, $coverage['total_likers']);
        $this->assertSame(300, $coverage['analyzed_likers']);
        $this->assertSame(32, $coverage['omitted_likers']);
        $this->assertSame(90.36, $coverage['sample_coverage_percent']);
        $this->assertTrue($coverage['sample_truncated']);
    }

    public function test_build_sample_coverage_when_all_likers_fit(): void
    {
        $coverage = $this->invoke('buildSampleCoverage', [120, 300]);

        $this->assertSame(120, $coverage['total_likers']);
        $this->assertSame(120, $coverage['analyzed_likers']);
        $this->assertSame(0, $coverage['omitted_likers']);
        $this->assertSame(100.0, $coverage['sample_coverage_percent']);
        $this->assertFalse($coverage['sample_truncated']);
    }

    public function test_format_erv_uses_total_likers(): void
    {
        $erv = $this->invoke('formatErv', [332, 16371]);

        $this->assertSame('2.03%', $erv);
    }

    public function test_build_profiles_meta(): void
    {
        $meta = $this->invoke('buildProfilesMeta', [300, 298]);

        $this->assertSame([
            'requested' => 300,
            'received' => 298,
            'unavailable' => 2,
        ], $meta);
    }

    public function test_compute_demographics_unavailable_is_independent_of_row_order(): void
    {
        $rows = [
            ['user_id' => 1, 'core_member' => true, 'friends_data_available' => true],
            ['user_id' => 2, 'core_member' => false, 'friends_data_available' => false],
            ['user_id' => 3, 'core_member' => false, 'friends_data_available' => true],
            ['user_id' => 4, 'core_member' => false, 'friends_data_available' => true],
        ];

        $profiles = [
            1 => ['sex' => 2, 'bdate' => '1.1.1990'],
            3 => ['sex' => 1, 'bdate' => '2.2.1995'],
            // 2 and 4 intentionally missing
        ];

        $cmd = new LikersCore();
        $method = new ReflectionMethod(LikersCore::class, 'computeDemographics');
        $method->setAccessible(true);

        $forward = $method->invoke($cmd, $rows, $profiles, 2);
        $reversed = $method->invoke($cmd, array_reverse($rows), $profiles, 2);

        $this->assertSame(0, $forward['core']['profiles_unavailable']);
        $this->assertSame(1, $forward['hidden']['profiles_unavailable']);
        $this->assertSame(1, $forward['open']['profiles_unavailable']);

        $this->assertSame(
            $forward['core']['profiles_unavailable'] + $forward['hidden']['profiles_unavailable'] + $forward['open']['profiles_unavailable'],
            $reversed['core']['profiles_unavailable'] + $reversed['hidden']['profiles_unavailable'] + $reversed['open']['profiles_unavailable']
        );
        $this->assertSame($forward['core']['count'], $reversed['core']['count']);
        $this->assertSame($forward['hidden']['count'], $reversed['hidden']['count']);
        $this->assertSame($forward['open']['count'], $reversed['open']['count']);
    }

    public function test_compute_demographics_keeps_segment_integrity(): void
    {
        $rows = [
            ['user_id' => 10, 'core_member' => true, 'friends_data_available' => true],
            ['user_id' => 20, 'core_member' => false, 'friends_data_available' => false],
            ['user_id' => 30, 'core_member' => false, 'friends_data_available' => true],
        ];

        $profiles = [
            10 => ['sex' => 2, 'bdate' => '1.1.1980'],
            20 => ['sex' => 1, 'bdate' => ''],
            30 => ['sex' => 0, 'bdate' => '3.3.2000'],
        ];

        $demo = $this->invoke('computeDemographics', [$rows, $profiles, 2]);

        $this->assertSame(1, $demo['core']['count']);
        $this->assertSame(1, $demo['hidden']['count']);
        $this->assertSame(1, $demo['open']['count']);
        $this->assertSame(
            3,
            $demo['core']['count'] + $demo['hidden']['count'] + $demo['open']['count']
        );
        $this->assertSame(0, $demo['core']['profiles_unavailable']);
        $this->assertSame(0, $demo['hidden']['profiles_unavailable']);
        $this->assertSame(0, $demo['open']['profiles_unavailable']);
    }

    /**
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function invoke(string $methodName, array $args = [])
    {
        $cmd = new LikersCore();
        $method = new ReflectionMethod(LikersCore::class, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($cmd, $args);
    }
}
