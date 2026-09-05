<?php

namespace App\Console\Commands;

use App\Services\VkApi\VkFriendsService;
use App\Services\VkApi\VkLikesService;
use App\Services\VkApi\VkRequestException;
use App\Services\VkApi\VkUsersService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LikersCore extends Command
{
    private const TECHNICAL_FRIEND_CATEGORIES = [
        'api',
        'transport',
        'rate_limit',
        'flood',
        'unexpected_response',
    ];

    private const HIDDEN_FRIEND_CATEGORIES = [
        'privacy',
        'access',
    ];

    private const TECHNICAL_STREAK_LIMIT = 5;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:likers-core
                            {--owner= : ID владельца стены (обязательный, отрицательное число для групп)}
                            {--post= : ID поста (обязательный)}
                            {--k=1 : Минимум друзей среди лайкнувших для включения в ядро}
                            {--max-users=300 : Максимум лайкнувших для анализа}
                            {--delay=0.2 : Задержка между запросами friends.get в секундах}
                            {--verbose-errors : Показать сводку ошибок API при получении друзей}
                            {--demographics : Показать демографию (пол/возраст) по сегментам}
                            {--format=table : Формат вывода: table, json, csv, markdown}
                            {--output= : Путь к файлу для сохранения результатов}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Вычисление ядра лайкнувших пост по дружеским связям между лайкнувшими';

    public function handle(
        VkLikesService $likesService,
        VkFriendsService $friendsService,
        VkUsersService $usersService
    ): int
    {
        $owner = $this->option('owner');
        $postId = (int) $this->option('post');
        $k = (int) $this->option('k');
        $maxUsers = (int) $this->option('max-users');
        $delay = (float) $this->option('delay');
        $verboseErrors = (bool) $this->option('verbose-errors');
        $format = strtolower((string) $this->option('format'));

        if (empty($owner)) {
            $this->error('Параметр --owner обязателен');
            return 1;
        }
        if ($postId <= 0) {
            $this->error('Параметр --post должен быть положительным числом');
            return 1;
        }
        if ($k < 1) {
            $this->error('Параметр --k должен быть >= 1');
            return 1;
        }
        if ($maxUsers < 1) {
            $this->error('Параметр --max-users должен быть >= 1');
            return 1;
        }
        if ($delay < 0) {
            $this->error('Параметр --delay должен быть >= 0');
            return 1;
        }
        if (!in_array($format, ['table', 'json', 'csv', 'markdown'], true)) {
            $this->error('Неверный формат. Допустимые значения: table, json, csv, markdown');
            return 1;
        }

        $postViews = DB::table('vk_posts')
            ->where('owner_id', $owner)
            ->where('post_id', $postId)
            ->value('views') ?? 0;

        $this->info("Получение лайкнувших пост {$owner}_{$postId}...");
        try {
            $likers = $this->getAllLikers($likesService, $owner, $postId);
        } catch (VkRequestException $e) {
            return $this->failRunOnException($e, $format, $owner, $postId, (int) $postViews, $k, $maxUsers, $delay);
        }
        if (empty($likers)) {
            $this->warn('Лайков не найдено.');
            return 0;
        }

        $totalLikers = count($likers);
        $coverage = $this->buildSampleCoverage($totalLikers, $maxUsers);
        $sampleTruncated = $coverage['sample_truncated'];
        $analyzedLikers = $coverage['analyzed_likers'];
        $omittedLikers = $coverage['omitted_likers'];
        $sampleCoveragePercent = $coverage['sample_coverage_percent'];

        if ($sampleTruncated) {
            $this->warn("Лайкнувших: {$totalLikers}. Будут обработаны первые {$maxUsers} пользователей (не случайная выборка).");
            $likers = array_slice($likers, 0, $maxUsers);
        }

        $likerSet = array_fill_keys($likers, true);
        $rows = [];
        $friendErrors = 0;
        $errorStats = [];
        $errorCounts = [];
        $runStatus = 'complete';
        $stoppedBy = null;
        $stopFriendsResult = null;
        $skipFriendsResult = null;
        $friendsCalls = 0;
        $technicalStreak = 0;

        $this->info('Анализ дружеских связей...');
        $bar = $this->output->createProgressBar(count($likers));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('friends.get...');
        $bar->start();

        foreach ($likers as $userId) {
            $friendsCalls++;
            $friendsResult = $friendsService->getFriendIdsWithError((int) $userId);
            $friends = $friendsResult['friends'];
            $errorMessage = $friendsResult['error'];
            $interpretation = $this->interpretFriendResult($friendsResult, $technicalStreak);
            $technicalStreak = $interpretation['technical_streak'];

            if ($friends === null) {
                $friendErrors++;
                $errorCounts = $this->tallyErrorCategory(
                    $interpretation['category'] ?? $friendsResult['category'] ?? null,
                    $errorCounts
                );
                $errorKey = $this->normalizeErrorMessage($errorMessage);
                if (!isset($errorStats[$errorKey])) {
                    $errorStats[$errorKey] = ['count' => 0, 'users' => []];
                }
                $errorStats[$errorKey]['count']++;
                if (count($errorStats[$errorKey]['users']) < 5) {
                    $errorStats[$errorKey]['users'][] = (int) $userId;
                }
            }

            if ($interpretation['decision'] === 'stop') {
                $runStatus = 'failed';
                $stoppedBy = $interpretation['category'];
                $stopFriendsResult = $friendsResult;
                break;
            }

            if ($interpretation['decision'] === 'skip') {
                $runStatus = 'failed';
                if ($stoppedBy === null) {
                    $stoppedBy = $interpretation['category'];
                }
                if ($skipFriendsResult === null) {
                    $skipFriendsResult = $friendsResult;
                }
                $bar->advance();
                if ($delay > 0) {
                    usleep((int) ($delay * 1000000));
                }
                continue;
            }

            if ($interpretation['decision'] === 'hidden') {
                $rows[] = [
                    'user_id' => (int) $userId,
                    'friends_in_likers_count' => 0,
                    'core_member' => false,
                    'friends_data_available' => false,
                    'error_message' => $errorMessage,
                    'error_category' => $interpretation['category'],
                ];
            } else {
                $friendsInLikers = 0;
                if (is_array($friends)) {
                    foreach ($friends as $friendId) {
                        if (isset($likerSet[$friendId])) {
                            $friendsInLikers++;
                        }
                    }
                }
                $rows[] = [
                    'user_id' => (int) $userId,
                    'friends_in_likers_count' => $friendsInLikers,
                    'core_member' => $friendsInLikers >= $k,
                    'friends_data_available' => true,
                    'error_message' => null,
                    'error_category' => null,
                ];
            }

            $bar->advance();
            if ($delay > 0) {
                usleep((int) ($delay * 1000000));
            }
        }

        $bar->setMessage('');
        $bar->finish();
        $this->newLine(2);

        if (!empty($errorCounts)) {
            $this->info('Ошибки friends.get по категориям:');
            $this->table(['Категория', 'Количество'], $this->formatCategoryCountRows($errorCounts));

            if ($verboseErrors && !empty($errorStats)) {
                $this->line('Детали по тексту ошибки:');
                $errorRows = [];
                foreach ($errorStats as $error => $meta) {
                    $errorRows[] = [$error, $meta['count']];
                }
                usort($errorRows, fn(array $a, array $b) => $b[1] <=> $a[1]);
                $this->table(['Причина', 'Количество'], $errorRows);

                $this->line('Примеры user_id:');
                foreach ($errorStats as $error => $meta) {
                    $this->line("  {$error}: " . implode(', ', $meta['users']));
                }
            }
            $this->newLine();
        }

        usort($rows, fn(array $a, array $b) => $b['friends_in_likers_count'] <=> $a['friends_in_likers_count']);

        $withDemographics = (bool) $this->option('demographics');
        $demographics = null;
        $profiles = [];
        $profilesMeta = $this->buildProfilesMeta(0, 0);

        if ($this->shouldFetchProfiles($runStatus)) {
            $allIds = array_map(fn(array $r) => (int) $r['user_id'], $rows);
            $fields = $this->resolveProfileFields($withDemographics);
            if ($withDemographics) {
                $this->info('Получение профилей и демографических данных...');
            }

            try {
                $profiles = $usersService->getByIds($allIds, $fields, $delay);
                $profilesMeta = $this->buildProfilesMeta(count($allIds), count($profiles));
                $requested = $profilesMeta['requested'];
                $received = $profilesMeta['received'];
                if ($requested > 0 && $received / $requested < 0.9) {
                    $this->warn("Получено профилей: {$received} из {$requested}. Демография может быть смещена.");
                }
            } catch (VkRequestException $e) {
                if (!$e->stopsRun) {
                    throw $e;
                }
                $runStatus = 'failed';
                $stoppedBy = $e->category;
                $stopFriendsResult = [
                    'error' => $e->getMessage(),
                    'category' => $e->category,
                    'vk_code' => $e->vkCode,
                ];
                $errorCounts = $this->tallyErrorCategory($e->category, $errorCounts);
                $profiles = [];
                $profilesMeta = $this->buildProfilesMeta(0, 0);
            }
        }

        $rows = $this->enrichRowsWithProfiles($rows, $profiles);
        $coreUsers = array_values(array_filter($rows, fn(array $r) => $r['core_member']));

        if ($this->shouldComputeDemographics($runStatus, $withDemographics)) {
            $demographics = $this->computeDemographics($rows, $profiles, $k);
        }

        $this->persistSegmentsIfComplete($runStatus, $owner, $postId, $rows);
        if ($runStatus === 'failed') {
            $this->warn(
                $this->formatUnsavedSegmentsWarning(
                    $stopFriendsResult ?? $skipFriendsResult ?? [],
                    $friendsCalls
                )
            );
        }

        $result = [
            'post' => ['owner_id' => $owner, 'post_id' => $postId, 'views' => $postViews],
            'settings' => ['k' => $k, 'max_users' => $maxUsers, 'delay' => $delay],
            'run' => $this->buildRunMeta($runStatus, $errorCounts, $stoppedBy),
            'summary' => [
                'total_likers' => $totalLikers,
                'analyzed_likers' => $analyzedLikers,
                'omitted_likers' => $omittedLikers,
                'sample_coverage_percent' => $sampleCoveragePercent,
                'sample_truncated' => $sampleTruncated,
                'core_users_count' => count($coreUsers),
                'friend_data_errors' => $friendErrors,
                'friend_error_types' => $this->flattenErrorStats($errorStats),
            ],
            'profiles' => $profilesMeta,
            'core_users' => $coreUsers,
            'users' => $rows,
            'demographics' => $demographics,
        ];

        $outputWritten = $this->writeAndDisplayResult($result, $format);
        if (!$outputWritten) {
            return 1;
        }

        return $runStatus === 'complete' ? 0 : 1;
    }

    /**
     * @return array<int>
     * @throws VkRequestException
     */
    private function getAllLikers(VkLikesService $likesService, string $owner, int $postId): array
    {
        $offset = 0;
        $count = 1000;
        $seen = [];
        $all = [];
        $totalCount = null;

        while (true) {
            $chunk = $likesService->getPostLikers($owner, $postId, $count, $offset);
            if ($totalCount === null) {
                $totalCount = (int) ($chunk['total_count'] ?? 0);
            }

            $users = $chunk['user_ids'] ?? [];
            if (empty($users)) {
                break;
            }

            foreach ($users as $id) {
                $id = (int) $id;
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $all[] = $id;
                }
            }

            if (count($users) < $count) {
                break;
            }
            $offset += $count;
            if ($totalCount !== null && $offset >= $totalCount) {
                break;
            }
            usleep(250000);
        }

        return $all;
    }

    private function failRunOnException(
        VkRequestException $e,
        string $format,
        $owner,
        int $postId,
        int $postViews,
        int $k,
        int $maxUsers,
        float $delay
    ): int {
        $stop = [
            'error' => $e->getMessage(),
            'category' => $e->category,
            'vk_code' => $e->vkCode,
        ];
        $reason = $this->formatStoppedReason($stop);
        $this->error("Не удалось получить лайкнувших: {$reason}.");
        $this->warn($this->formatUnsavedSegmentsWarning($stop, 0));

        $errorCounts = $this->tallyErrorCategory($e->category, []);
        $result = [
            'post' => ['owner_id' => $owner, 'post_id' => $postId, 'views' => $postViews],
            'settings' => ['k' => $k, 'max_users' => $maxUsers, 'delay' => $delay],
            'run' => $this->buildRunMeta('failed', $errorCounts, $e->category),
            'summary' => [
                'total_likers' => 0,
                'analyzed_likers' => 0,
                'omitted_likers' => 0,
                'sample_coverage_percent' => 100.0,
                'sample_truncated' => false,
                'core_users_count' => 0,
                'friend_data_errors' => 0,
                'friend_error_types' => [],
            ],
            'profiles' => $this->buildProfilesMeta(0, 0),
            'core_users' => [],
            'users' => [],
            'demographics' => null,
        ];

        $this->writeAndDisplayResult($result, $format);

        return 1;
    }

    private function writeAndDisplayResult(array $result, string $format): bool
    {
        $outputPath = $this->option('output');
        if ($outputPath) {
            $saveFormat = $this->resolveFormatForOutput($format, (string) $outputPath);
            $content = $this->formatForFile($result, $saveFormat);
            $finalPath = $this->resolvePath((string) $outputPath);

            $dir = dirname($finalPath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $this->error("Не удалось создать директорию: {$dir}");
                return false;
            }

            $bytes = file_put_contents($finalPath, $content);
            if ($bytes === false) {
                $this->error("Ошибка при сохранении файла: {$finalPath}");
                return false;
            }

            $this->info("Результаты сохранены в файл: {$finalPath} ({$bytes} байт)");
            if ($format === 'table') {
                $this->displayTable($result);
            }

            return true;
        }

        if ($format === 'table') {
            $this->displayTable($result);
        } else {
            $this->line($this->formatNonTable($result, $format));
        }

        return true;
    }

    private function displayTable(array $result): void
    {
        $summary = $result['summary'];
        $post = $result['post'];
        $k = $result['settings']['k'];

        $views = $post['views'] ?? 0;
        $likesForErv = $summary['total_likers'] ?? $summary['analyzed_likers'];
        $erv = $this->formatErv((int) $likesForErv, (int) $views);

        $this->info("Пост: {$post['owner_id']}_{$post['post_id']}");
        $this->info("Порог ядра k: {$k}");

        if (!empty($summary['sample_truncated'])) {
            $this->warn(
                "Обработаны первые {$summary['analyzed_likers']} лайкнувших (порядок API), это не гарантированно случайная выборка."
            );
        }

        $run = $result['run'] ?? ['status' => 'complete', 'error_counts' => [], 'stopped_by' => null];
        $runRows = [
            ['Всего лайкнувших', $summary['total_likers'] ?? $summary['analyzed_likers']],
            ['Лайкнувших проанализировано', $summary['analyzed_likers']],
            ['Не обработано', $summary['omitted_likers'] ?? 0],
            ['Покрытие выборки', ($summary['sample_coverage_percent'] ?? 100) . '%'],
            ['Просмотров поста', $views],
            ['ERv (лайки / просмотры)', $erv],
            ['Пользователей в ядре', $summary['core_users_count']],
            ['Ошибок чтения друзей', $summary['friend_data_errors']],
            ['Статус прогона', $run['status']],
        ];
        if (($run['stopped_by'] ?? null) !== null) {
            $runRows[] = ['Остановлен из-за', $run['stopped_by']];
        }

        $this->table(['Показатель', 'Значение'], $runRows);

        if (empty($result['core_users'])) {
            $this->warn('Ядро пустое по выбранному порогу.');
            return;
        }

        $rows = array_map(function (array $r) {
            return [
                $r['user_id'],
                $r['display_name'],
                $r['screen_name'] ?: '-',
                $r['friends_in_likers_count'],
                $r['friends_data_available'] ? 'yes' : 'no',
            ];
        }, $result['core_users']);
        $this->table(
            ['user_id', 'name', 'screen_name', 'friends_in_likers_count', 'friends_data_available'],
            $rows
        );

        if (!empty($result['demographics'])) {
            $this->displayDemographics($result['demographics']);
        }
    }

    private function formatForFile(array $result, string $format): string
    {
        if ($format === 'table') {
            return $this->formatText($result);
        }
        return $this->formatNonTable($result, $format);
    }

    private function formatNonTable(array $result, string $format): string
    {
        if ($format === 'json') {
            return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '';
        }

        if ($format === 'csv') {
            $f = fopen('php://temp', 'r+');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['user_id', 'name', 'screen_name', 'profile_url', 'friends_in_likers_count', 'core_member', 'friends_data_available']);
            foreach ($result['users'] as $r) {
                fputcsv($f, [
                    $r['user_id'],
                    $r['display_name'],
                    $r['screen_name'] ?? '',
                    $r['profile_url'] ?? '',
                    $r['friends_in_likers_count'],
                    $r['core_member'] ? 1 : 0,
                    $r['friends_data_available'] ? 1 : 0,
                ]);
            }
            rewind($f);
            $csv = stream_get_contents($f);
            fclose($f);
            return $csv ?: '';
        }

        // markdown
        $summary = $result['summary'];
        $out = "# Ядро лайкнувших\n\n";
        $out .= "- Пост: `{$result['post']['owner_id']}_{$result['post']['post_id']}`\n";
        $out .= "- Порог k: `{$result['settings']['k']}`\n";
        $out .= "- Всего лайкнувших: `{$summary['total_likers']}`\n";
        $out .= "- Лайкнувших проанализировано: `{$summary['analyzed_likers']}`\n";
        $out .= "- Не обработано: `{$summary['omitted_likers']}`\n";
        $out .= "- Покрытие выборки: `{$summary['sample_coverage_percent']}%`\n";
        if (!empty($summary['sample_truncated'])) {
            $out .= "- Внимание: обработаны первые {$summary['analyzed_likers']} лайкнувших (порядок API), это не гарантированно случайная выборка.\n";
        }
        $out .= "- Пользователей в ядре: `{$summary['core_users_count']}`\n";
        $out .= $this->formatRunStatusLines($result, true) . "\n";
        $out .= "| user_id | name | screen_name | friends_in_likers_count | friends_data_available |\n";
        $out .= "|---:|---|---|---:|---:|\n";
        foreach ($result['core_users'] as $r) {
            $out .= "| {$r['user_id']} | {$r['display_name']} | " . ($r['screen_name'] ?: '-') . " | {$r['friends_in_likers_count']} | " . ($r['friends_data_available'] ? '1' : '0') . " |\n";
        }
        if (empty($result['core_users'])) {
            $out .= "| - | - | - | - | - |\n";
        }
        return $out;
    }

    private function formatText(array $result): string
    {
        $summary = $result['summary'];
        $out = "Ядро лайкнувших\n";
        $out .= "Пост: {$result['post']['owner_id']}_{$result['post']['post_id']}\n";
        $out .= "Порог k: {$result['settings']['k']}\n";
        $out .= "Всего лайкнувших: {$summary['total_likers']}\n";
        $out .= "Лайкнувших проанализировано: {$summary['analyzed_likers']}\n";
        $out .= "Не обработано: {$summary['omitted_likers']}\n";
        $out .= "Покрытие выборки: {$summary['sample_coverage_percent']}%\n";
        if (!empty($summary['sample_truncated'])) {
            $out .= "Внимание: обработаны первые {$summary['analyzed_likers']} лайкнувших (порядок API), это не гарантированно случайная выборка.\n";
        }
        $out .= "Пользователей в ядре: {$summary['core_users_count']}\n";
        $out .= "Ошибок чтения друзей: {$summary['friend_data_errors']}\n";
        $out .= $this->formatRunStatusLines($result, false) . "\n";
        foreach ($result['core_users'] as $r) {
            $out .= "- user_id={$r['user_id']}, name={$r['display_name']}, screen_name=" . ($r['screen_name'] ?: '-') . ", friends_in_likers_count={$r['friends_in_likers_count']}, friends_data_available=" . ($r['friends_data_available'] ? '1' : '0') . "\n";
        }
        if (empty($result['core_users'])) {
            $out .= "- ядро пустое\n";
        }
        return $out;
    }

    private function resolveFormatForOutput(string $format, string $outputPath): string
    {
        $ext = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            return 'json';
        }
        if ($ext === 'csv') {
            return 'csv';
        }
        if (in_array($ext, ['md', 'markdown'], true)) {
            return 'markdown';
        }
        return $format;
    }

    private function resolvePath(string $path): string
    {
        if (strpos($path, '/') === 0) {
            return $path;
        }
        return base_path($path);
    }

    /**
     * @param array<string, int> $errorCounts
     * @return array<string, int>
     */
    private function tallyErrorCategory(?string $category, array $errorCounts): array
    {
        $key = $category ?: 'unknown';
        $errorCounts[$key] = ($errorCounts[$key] ?? 0) + 1;

        return $errorCounts;
    }

    /**
     * @param array<string, int> $errorCounts
     * @return array{status:'complete'|'failed', error_counts:array<string, int>, stopped_by:?string}
     */
    private function buildRunMeta(string $status, array $errorCounts, ?string $stoppedBy): array
    {
        $status = $status === 'complete' ? 'complete' : 'failed';
        ksort($errorCounts);

        return [
            'status' => $status,
            'error_counts' => $errorCounts,
            'stopped_by' => $stoppedBy,
        ];
    }

    /**
     * @param array{error:?string, vk_code?:?int, category?:?string} $friendsResult
     */
    private function formatStoppedReason(array $friendsResult): string
    {
        $message = trim((string) ($friendsResult['error'] ?? ''));
        $category = $friendsResult['category'] ?? null;
        if ($message === '') {
            $message = $category ?: 'unknown';
        }

        $vkCode = $friendsResult['vk_code'] ?? null;
        if (is_int($vkCode) && !preg_match('/код\s+\d+/u', $message)) {
            return "{$message} (код {$vkCode})";
        }

        return $message;
    }

    /**
     * @param array{error:?string, vk_code?:?int, category?:?string} $friendsResult
     */
    private function formatUnsavedSegmentsWarning(array $friendsResult, int $processedCount): string
    {
        $reason = $this->formatStoppedReason($friendsResult);

        return "Сегменты не сохранены: {$reason}. Прогон остановлен после {$processedCount} пользователей.";
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function persistSegmentsIfComplete(string $runStatus, $owner, int $postId, array $rows): void
    {
        if ($runStatus !== 'complete' || !Schema::hasTable('user_post_segments')) {
            return;
        }

        $segments = $this->buildSegmentRows($rows, $owner, $postId);
        if ($segments === []) {
            return;
        }

        DB::transaction(function () use ($segments) {
            DB::table('user_post_segments')->upsert(
                $segments,
                ['owner_id', 'post_id', 'user_id'],
                ['segment', 'friends_in_likers_count']
            );
        });
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{user_id:int, owner_id:mixed, post_id:int, segment:string, friends_in_likers_count:int}>
     */
    private function buildSegmentRows(array $rows, $owner, int $postId): array
    {
        $segments = [];
        foreach ($rows as $r) {
            $seg = $this->resolveSegment($r);
            $segments[] = [
                'user_id' => $r['user_id'],
                'owner_id' => $owner,
                'post_id' => $postId,
                'segment' => $seg,
                'friends_in_likers_count' => $r['friends_in_likers_count'] ?? 0,
            ];
        }

        return $segments;
    }

    private function formatRunStatusLines(array $result, bool $markdown): string
    {
        $run = $result['run'] ?? ['status' => 'complete', 'error_counts' => [], 'stopped_by' => null];
        $status = $run['status'] ?? 'complete';
        $stoppedBy = $run['stopped_by'] ?? null;

        if ($markdown) {
            $lines = "- Статус прогона: `{$status}`\n";
            if ($stoppedBy !== null) {
                $lines .= "- Остановлен из-за: `{$stoppedBy}`\n";
            }
            return $lines;
        }

        $lines = "Статус прогона: {$status}\n";
        if ($stoppedBy !== null) {
            $lines .= "Остановлен из-за: {$stoppedBy}\n";
        }

        return $lines;
    }

    /**
     * Решение по одному ответу friends.get: стоп, hidden, пропуск или обычный подсчёт.
     *
     * @param array{friends:?array, error:?string, category:?string, stops_run?:bool} $friendsResult
     * @return array{decision:'stop'|'hidden'|'skip'|'ok', technical_streak:int, category:?string}
     */
    private function interpretFriendResult(array $friendsResult, int $technicalStreak): array
    {
        $category = $friendsResult['category'] ?? null;

        if ($friendsResult['stops_run'] ?? false) {
            return [
                'decision' => 'stop',
                'technical_streak' => $technicalStreak,
                'category' => $category,
            ];
        }

        if (in_array($category, self::HIDDEN_FRIEND_CATEGORIES, true)) {
            return [
                'decision' => 'hidden',
                'technical_streak' => 0,
                'category' => $category,
            ];
        }

        $friends = $friendsResult['friends'] ?? null;
        if ($friends === null && in_array($category, self::TECHNICAL_FRIEND_CATEGORIES, true)) {
            $streak = $technicalStreak + 1;
            if ($streak >= self::TECHNICAL_STREAK_LIMIT) {
                return [
                    'decision' => 'stop',
                    'technical_streak' => $streak,
                    'category' => $category,
                ];
            }

            return [
                'decision' => 'skip',
                'technical_streak' => $streak,
                'category' => $category,
            ];
        }

        return [
            'decision' => 'ok',
            'technical_streak' => 0,
            'category' => $category,
        ];
    }

    /**
     * @return 'core'|'hidden'|'open'
     */
    private function resolveSegment(array $row): string
    {
        if (!empty($row['core_member'])) {
            return 'core';
        }

        $available = (bool) ($row['friends_data_available'] ?? false);
        $category = $row['error_category'] ?? null;
        if (!$available && in_array($category, self::HIDDEN_FRIEND_CATEGORIES, true)) {
            return 'hidden';
        }

        return 'open';
    }

    /**
     * @param array<string, int> $errorCounts
     * @return array<int, array{0:string,1:int}>
     */
    private function formatCategoryCountRows(array $errorCounts): array
    {
        $rows = [];
        foreach ($errorCounts as $category => $count) {
            $rows[] = [(string) $category, (int) $count];
        }
        usort($rows, function (array $a, array $b): int {
            $byCount = $b[1] <=> $a[1];
            return $byCount !== 0 ? $byCount : $a[0] <=> $b[0];
        });

        return $rows;
    }

    /**
     * @param array<string, array{count:int,users:array<int>}> $errorStats
     * @return array<int, array{error:string,count:int,sample_users:array<int>}>
     */
    private function flattenErrorStats(array $errorStats): array
    {
        $result = [];
        foreach ($errorStats as $error => $meta) {
            $result[] = [
                'error' => $error,
                'count' => $meta['count'],
                'sample_users' => $meta['users'],
            ];
        }

        usort($result, fn(array $a, array $b) => $b['count'] <=> $a['count']);
        return $result;
    }

    private function normalizeErrorMessage(?string $errorMessage): string
    {
        if ($errorMessage === null || trim($errorMessage) === '') {
            return 'Unknown error';
        }

        $message = trim($errorMessage);
        // Strip user-specific IDs to group similar errors
        $message = preg_replace('/for user \d+/', 'for user *', $message);
        if (mb_strlen($message) > 120) {
            $message = mb_substr($message, 0, 120) . '...';
        }

        return $message;
    }

    /**
     * @return array{
     *   total_likers:int,
     *   analyzed_likers:int,
     *   omitted_likers:int,
     *   sample_coverage_percent:float,
     *   sample_truncated:bool
     * }
     */
    private function buildSampleCoverage(int $totalLikers, int $maxUsers): array
    {
        $sampleTruncated = $totalLikers > $maxUsers;
        $analyzedLikers = $sampleTruncated ? $maxUsers : $totalLikers;
        $omittedLikers = $totalLikers - $analyzedLikers;

        return [
            'total_likers' => $totalLikers,
            'analyzed_likers' => $analyzedLikers,
            'omitted_likers' => $omittedLikers,
            'sample_coverage_percent' => $totalLikers > 0
                ? round($analyzedLikers / $totalLikers * 100, 2)
                : 100.0,
            'sample_truncated' => $sampleTruncated,
        ];
    }

    /**
     * @return array{requested:int,received:int,unavailable:int}
     */
    private function buildProfilesMeta(int $requested, int $received): array
    {
        return [
            'requested' => $requested,
            'received' => $received,
            'unavailable' => max(0, $requested - $received),
        ];
    }

    private function shouldFetchProfiles(string $runStatus): bool
    {
        return $runStatus === 'complete';
    }

    private function shouldComputeDemographics(string $runStatus, bool $requested): bool
    {
        return $runStatus === 'complete' && $requested;
    }

    /**
     * @return array<int, string>
     */
    private function resolveProfileFields(bool $demographics): array
    {
        if ($demographics) {
            return ['screen_name', 'bdate', 'sex'];
        }

        return ['screen_name'];
    }

    private function formatErv(int $totalLikers, int $views): string
    {
        return $views > 0 ? round($totalLikers / $views * 100, 2) . '%' : 'N/A';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $profiles
     * @return array<int, array<string, mixed>>
     */
    private function enrichRowsWithProfiles(array $rows, array $profiles): array
    {
        $base = rtrim((string) config('vk.account_base_url', 'https://vk.com'), '/');

        foreach ($rows as &$row) {
            $userId = (int) $row['user_id'];
            $profile = $profiles[$userId] ?? null;

            $firstName = is_array($profile) ? (string) ($profile['first_name'] ?? '') : '';
            $lastName = is_array($profile) ? (string) ($profile['last_name'] ?? '') : '';
            $screenName = is_array($profile) ? (string) ($profile['screen_name'] ?? '') : '';

            $displayName = trim($firstName . ' ' . $lastName);
            if ($displayName === '') {
                $displayName = "id{$userId}";
            }

            $row['first_name'] = $firstName;
            $row['last_name'] = $lastName;
            $row['screen_name'] = $screenName;
            $row['display_name'] = $displayName;
            $row['profile_url'] = $screenName !== '' ? "{$base}/{$screenName}" : "{$base}/id{$userId}";
        }
        unset($row);

        return $rows;
    }

    private function computeDemographics(array $rows, array $demoProfiles, int $k): array
    {
        $segments = ['core' => [], 'hidden' => [], 'open' => []];

        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            $segments[$this->resolveSegment($r)][] = $uid;
        }

        // Проверка целостности: сегменты взаимоисключающие, покрывают всех
        $totalSegments = count($segments['core']) + count($segments['hidden']) + count($segments['open']);
        $coreHiddenOverlap = array_intersect($segments['core'], $segments['hidden']);
        $coreOpenOverlap = array_intersect($segments['core'], $segments['open']);
        $hiddenOpenOverlap = array_intersect($segments['hidden'], $segments['open']);
        if ($totalSegments !== count($rows)
            || $coreHiddenOverlap || $coreOpenOverlap || $hiddenOpenOverlap) {
            throw new \RuntimeException(sprintf(
                'Integrity: segments=%d rows=%d, overlaps: core∩hidden=%d core∩open=%d hidden∩open=%d',
                $totalSegments, count($rows),
                count($coreHiddenOverlap), count($coreOpenOverlap), count($hiddenOpenOverlap)
            ));
        }

        $result = [];
        foreach ($segments as $seg => $userIds) {
            $stats = $this->segmentDemographics($userIds, $demoProfiles);
            $stats['count'] = count($userIds);
            $stats['user_ids'] = $userIds;
            $result[$seg] = $stats;
        }

        return $result;
    }

    private function segmentDemographics(array $userIds, array $profiles): array
    {
        $male = 0;
        $female = 0;
        $unknownSex = 0;
        $withAge = 0;
        $noAge = 0;
        $profilesUnavailable = 0;
        $ageSum = 0;
        $ageCount = 0;

        foreach ($userIds as $uid) {
            $p = $profiles[$uid] ?? null;
            if (!is_array($p)) {
                $profilesUnavailable++;
                continue;
            }

            $sex = (int) ($p['sex'] ?? 0);
            if ($sex === 2) {
                $male++;
            } elseif ($sex === 1) {
                $female++;
            } else {
                $unknownSex++;
            }

            $bdate = (string) ($p['bdate'] ?? '');
            $year = $this->extractBirthYear($bdate);
            if ($year !== null && $year > 1900) {
                $withAge++;
                $ageSum += (2026 - $year);
                $ageCount++;
            } else {
                $noAge++;
            }
        }

        return [
            'male' => $male,
            'female' => $female,
            'unknown_sex' => $unknownSex,
            'profiles_unavailable' => $profilesUnavailable,
            'with_age' => $withAge,
            'no_age' => $noAge,
            'avg_age' => $ageCount > 0 ? round($ageSum / $ageCount, 1) : null,
        ];
    }

    private function extractBirthYear(string $bdate): ?int
    {
        if ($bdate === '') {
            return null;
        }
        $parts = explode('.', $bdate);
        if (count($parts) >= 3) {
            return (int) $parts[2];
        }
        return null;
    }

    private function displayDemographics(array $demo): void
    {
        $this->newLine();
        $this->info('=== Демография ===');
        $this->line('(доли указаны от числа профилей с известными данными; профили без данных — отдельная колонка)');
        $this->newLine();

        $rows = [];
        $labels = ['core' => 'Ядро (k≥2)', 'hidden' => 'Скрытые', 'open' => 'Открытые'];

        foreach (['core', 'hidden', 'open'] as $seg) {
            $d = $demo[$seg] ?? null;
            if (!$d || $d['count'] === 0) {
                continue;
            }
            $total = $d['count'];
            $knownSex = $d['male'] + $d['female'];
            $knownAge = $d['with_age'] + $d['no_age'];
            $unavail = $d['profiles_unavailable'] ?? 0;

            $pctTotal = fn(int $n) => $total > 0 ? round($n / $total * 100) . '%' : '-';
            $pctSex = fn(int $n) => $knownSex > 0 ? round($n / $knownSex * 100) . '%' : '-';
            $pctAge = fn(int $n) => $knownAge > 0 ? round($n / $knownAge * 100) . '%' : '-';

            $rows[] = [
                $labels[$seg],
                $total,
                $d['male'] . ' / ' . $d['female'] . ($d['unknown_sex'] > 0 ? ' (+' . $d['unknown_sex'] . ' ?)' : ''),
                $pctSex($d['female']),
                $pctTotal($d['with_age']),
                $d['avg_age'] !== null ? $d['avg_age'] : '-',
                $d['no_age'] . ' (' . $pctAge($d['no_age']) . ')',
                $unavail > 0 ? $unavail . ' (' . $pctTotal($unavail) . ')' : '-',
            ];
        }

        $this->table(
            ['Сегмент', 'Всего', '♂ / ♀', '♀ доля*', 'с возр.', 'ср. возр.', 'скрыли возр.**', 'нет профиля'],
            $rows
        );

        $this->line('*  ♀ доля — от числа профилей с известным полом (♂+♀)');
        $this->line('** скрыли возр. — от числа профилей с известной датой рождения (с возр.+скрыли)');
    }
}

