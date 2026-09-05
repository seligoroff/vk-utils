<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\LikersCore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_should_fetch_profiles_only_when_complete(): void
    {
        $this->assertTrue($this->invoke('shouldFetchProfiles', ['complete']));
        $this->assertFalse($this->invoke('shouldFetchProfiles', ['failed']));
    }

    public function test_should_compute_demographics_only_when_complete_and_requested(): void
    {
        $this->assertTrue($this->invoke('shouldComputeDemographics', ['complete', true]));
        $this->assertFalse($this->invoke('shouldComputeDemographics', ['complete', false]));
        $this->assertFalse($this->invoke('shouldComputeDemographics', ['failed', true]));
        $this->assertFalse($this->invoke('shouldComputeDemographics', ['failed', false]));
    }

    public function test_compute_demographics_unavailable_is_independent_of_row_order(): void
    {
        $rows = [
            ['user_id' => 1, 'core_member' => true, 'friends_data_available' => true],
            ['user_id' => 2, 'core_member' => false, 'friends_data_available' => false, 'error_category' => 'privacy'],
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
            ['user_id' => 20, 'core_member' => false, 'friends_data_available' => false, 'error_category' => 'privacy'],
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

    public function test_compute_demographics_does_not_treat_technical_errors_as_hidden(): void
    {
        $rows = [
            ['user_id' => 1, 'core_member' => false, 'friends_data_available' => false, 'error_category' => 'flood'],
            ['user_id' => 2, 'core_member' => false, 'friends_data_available' => false, 'error_category' => 'privacy'],
            ['user_id' => 3, 'core_member' => false, 'friends_data_available' => true],
        ];

        $demo = $this->invoke('computeDemographics', [$rows, [], 2]);

        $this->assertSame(0, $demo['core']['count']);
        $this->assertSame(1, $demo['hidden']['count']);
        $this->assertSame(2, $demo['open']['count']);
        $this->assertSame([2], $demo['hidden']['user_ids']);
        $this->assertSame([1, 3], $demo['open']['user_ids']);
    }

    public function test_interpret_friend_result_stops_on_stops_run(): void
    {
        $result = $this->invoke('interpretFriendResult', [[
            'friends' => null,
            'error' => 'Flood control',
            'category' => 'flood',
            'stops_run' => true,
        ], 0]);

        $this->assertSame('stop', $result['decision']);
        $this->assertSame('flood', $result['category']);
        $this->assertSame(0, $result['technical_streak']);
    }

    public function test_interpret_friend_result_privacy_and_access_are_hidden(): void
    {
        $privacy = $this->invoke('interpretFriendResult', [[
            'friends' => null,
            'error' => 'Private profile',
            'category' => 'privacy',
            'stops_run' => false,
        ], 3]);

        $this->assertSame('hidden', $privacy['decision']);
        $this->assertSame('privacy', $privacy['category']);
        $this->assertSame(0, $privacy['technical_streak']);

        $access = $this->invoke('interpretFriendResult', [[
            'friends' => null,
            'error' => 'Access denied',
            'category' => 'access',
            'stops_run' => false,
        ], 1]);

        $this->assertSame('hidden', $access['decision']);
        $this->assertSame('access', $access['category']);
        $this->assertSame(0, $access['technical_streak']);
    }

    public function test_interpret_friend_result_technical_streak_skips_then_stops(): void
    {
        $first = $this->invoke('interpretFriendResult', [[
            'friends' => null,
            'error' => 'network',
            'category' => 'transport',
            'stops_run' => false,
        ], 0]);

        $this->assertSame('skip', $first['decision']);
        $this->assertSame(1, $first['technical_streak']);

        $fourth = $this->invoke('interpretFriendResult', [[
            'friends' => null,
            'error' => 'network',
            'category' => 'transport',
            'stops_run' => false,
        ], 3]);

        $this->assertSame('skip', $fourth['decision']);
        $this->assertSame(4, $fourth['technical_streak']);

        $fifth = $this->invoke('interpretFriendResult', [[
            'friends' => null,
            'error' => 'network',
            'category' => 'api',
            'stops_run' => false,
        ], 4]);

        $this->assertSame('stop', $fifth['decision']);
        $this->assertSame(5, $fifth['technical_streak']);
        $this->assertSame('api', $fifth['category']);
    }

    public function test_interpret_friend_result_success_resets_technical_streak(): void
    {
        $result = $this->invoke('interpretFriendResult', [[
            'friends' => [2, 3],
            'error' => null,
            'category' => null,
            'stops_run' => false,
        ], 4]);

        $this->assertSame('ok', $result['decision']);
        $this->assertSame(0, $result['technical_streak']);
    }

    public function test_build_run_meta_complete_without_partial(): void
    {
        $meta = $this->invoke('buildRunMeta', ['complete', ['privacy' => 2], null]);

        $this->assertSame([
            'status' => 'complete',
            'error_counts' => ['privacy' => 2],
            'stopped_by' => null,
        ], $meta);
        $this->assertContains($meta['status'], ['complete', 'failed']);
    }

    public function test_build_run_meta_failed_with_stopped_by(): void
    {
        $meta = $this->invoke('buildRunMeta', ['failed', ['transport' => 4, 'flood' => 1], 'flood']);

        $this->assertSame('failed', $meta['status']);
        $this->assertSame([
            'flood' => 1,
            'transport' => 4,
        ], $meta['error_counts']);
        $this->assertSame('flood', $meta['stopped_by']);
    }

    public function test_build_run_meta_does_not_emit_partial(): void
    {
        $meta = $this->invoke('buildRunMeta', ['partial', [], 'unexpected_response']);

        $this->assertSame('failed', $meta['status']);
        $this->assertSame('unexpected_response', $meta['stopped_by']);
    }

    public function test_tally_error_category_groups_counts(): void
    {
        $counts = [];
        $counts = $this->invoke('tallyErrorCategory', ['privacy', $counts]);
        $counts = $this->invoke('tallyErrorCategory', ['flood', $counts]);
        $counts = $this->invoke('tallyErrorCategory', ['privacy', $counts]);
        $counts = $this->invoke('tallyErrorCategory', [null, $counts]);

        $this->assertSame([
            'privacy' => 2,
            'flood' => 1,
            'unknown' => 1,
        ], $counts);
    }

    public function test_format_unsaved_segments_warning_includes_vk_code(): void
    {
        $message = $this->invoke('formatUnsavedSegmentsWarning', [[
            'error' => 'Flood control',
            'category' => 'flood',
            'vk_code' => 9,
        ], 37]);

        $this->assertSame(
            'Сегменты не сохранены: Flood control (код 9). Прогон остановлен после 37 пользователей.',
            $message
        );
    }

    public function test_persist_segments_writes_only_when_complete(): void
    {
        $this->ensureSegmentsTable();
        DB::table('user_post_segments')->delete();

        $rows = [
            ['user_id' => 1, 'core_member' => true, 'friends_data_available' => true, 'friends_in_likers_count' => 2],
            ['user_id' => 2, 'core_member' => false, 'friends_data_available' => false, 'friends_in_likers_count' => 0, 'error_category' => 'privacy'],
        ];

        $this->invoke('persistSegmentsIfComplete', ['failed', '-670335', 37639, $rows]);
        $this->assertSame(0, DB::table('user_post_segments')->count());

        $this->invoke('persistSegmentsIfComplete', ['complete', '-670335', 37639, $rows]);
        $this->assertSame(2, DB::table('user_post_segments')->count());
        $this->assertSame(
            ['core', 'hidden'],
            DB::table('user_post_segments')->orderBy('user_id')->pluck('segment')->all()
        );
    }

    public function test_resolve_segment_hidden_only_for_privacy_and_access(): void
    {
        $this->assertSame('core', $this->invoke('resolveSegment', [[
            'core_member' => true,
            'friends_data_available' => true,
        ]]));
        $this->assertSame('hidden', $this->invoke('resolveSegment', [[
            'core_member' => false,
            'friends_data_available' => false,
            'error_category' => 'privacy',
        ]]));
        $this->assertSame('hidden', $this->invoke('resolveSegment', [[
            'core_member' => false,
            'friends_data_available' => false,
            'error_category' => 'access',
        ]]));
        $this->assertSame('open', $this->invoke('resolveSegment', [[
            'core_member' => false,
            'friends_data_available' => false,
            'error_category' => 'flood',
        ]]));
        $this->assertSame('open', $this->invoke('resolveSegment', [[
            'core_member' => false,
            'friends_data_available' => true,
        ]]));
    }

    public function test_technical_unavailable_row_is_not_upserted_as_hidden(): void
    {
        $this->ensureSegmentsTable();
        DB::table('user_post_segments')->delete();

        $rows = [
            [
                'user_id' => 7,
                'core_member' => false,
                'friends_data_available' => false,
                'friends_in_likers_count' => 0,
                'error_category' => 'flood',
            ],
        ];

        $this->invoke('persistSegmentsIfComplete', ['complete', '-670335', 1, $rows]);

        $this->assertSame(
            ['open'],
            DB::table('user_post_segments')->pluck('segment')->all()
        );
    }

    public function test_format_category_count_rows_orders_by_count(): void
    {
        $rows = $this->invoke('formatCategoryCountRows', [[
            'privacy' => 2,
            'flood' => 5,
            'transport' => 5,
        ]]);

        $this->assertSame([
            ['flood', 5],
            ['transport', 5],
            ['privacy', 2],
        ], $rows);
    }

    private function ensureSegmentsTable(): void
    {
        if (Schema::hasTable('user_post_segments')) {
            return;
        }

        Schema::create('user_post_segments', function ($table) {
            $table->unsignedBigInteger('user_id');
            $table->string('owner_id', 32);
            $table->unsignedInteger('post_id');
            $table->string('segment', 16);
            $table->unsignedInteger('friends_in_likers_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['owner_id', 'post_id', 'user_id'], 'ups_owner_post_user');
        });
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
