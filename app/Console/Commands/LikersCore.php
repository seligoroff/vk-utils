<?php

namespace App\Console\Commands;

use App\Services\VkApi\VkFriendsService;
use App\Services\VkApi\VkLikesService;
use App\Services\VkApi\VkUsersService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LikersCore extends Command
{
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

    public function handle(): int
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

        $likesService = new VkLikesService();
        $friendsService = new VkFriendsService();
        $usersService = new VkUsersService();

        $postViews = DB::table('vk_posts')
            ->where('owner_id', $owner)
            ->where('post_id', $postId)
            ->value('views') ?? 0;

        $this->info("Получение лайкнувших пост {$owner}_{$postId}...");
        $likers = $this->getAllLikers($likesService, $owner, $postId);
        if ($likers === null) {
            $this->error('Не удалось получить лайкнувших. Проверьте owner/post и права токена.');
            return 1;
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

        $this->info('Анализ дружеских связей...');
        $bar = $this->output->createProgressBar(count($likers));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('friends.get...');
        $bar->start();

        foreach ($likers as $userId) {
            $friendsResult = $friendsService->getFriendIdsWithError((int) $userId);
            $friends = $friendsResult['friends'];
            $errorMessage = $friendsResult['error'];

            if ($friends === null) {
                $friendErrors++;
                $errorKey = $this->normalizeErrorMessage($errorMessage);
                if (!isset($errorStats[$errorKey])) {
                    $errorStats[$errorKey] = ['count' => 0, 'users' => []];
                }
                $errorStats[$errorKey]['count']++;
                if (count($errorStats[$errorKey]['users']) < 5) {
                    $errorStats[$errorKey]['users'][] = (int) $userId;
                }

                $rows[] = [
                    'user_id' => (int) $userId,
                    'friends_in_likers_count' => 0,
                    'core_member' => false,
                    'friends_data_available' => false,
                    'error_message' => $errorMessage,
                ];
            } else {
                $friendsInLikers = 0;
                foreach ($friends as $friendId) {
                    if (isset($likerSet[$friendId])) {
                        $friendsInLikers++;
                    }
                }
                $rows[] = [
                    'user_id' => (int) $userId,
                    'friends_in_likers_count' => $friendsInLikers,
                    'core_member' => $friendsInLikers >= $k,
                    'friends_data_available' => true,
                    'error_message' => null,
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

        if (!empty($errorStats)) {
            $this->info('Причины ошибок friends.get:');
            $errorRows = [];
            foreach ($errorStats as $error => $meta) {
                $errorRows[] = [$error, $meta['count']];
            }
            usort($errorRows, fn(array $a, array $b) => $b[1] <=> $a[1]);
            $this->table(['Причина', 'Количество'], $errorRows);

            if ($verboseErrors) {
                $this->line('Примеры user_id:');
                foreach ($errorStats as $error => $meta) {
                    $this->line("  {$error}: " . implode(', ', $meta['users']));
                }
            }
            $this->newLine();
        }

        usort($rows, fn(array $a, array $b) => $b['friends_in_likers_count'] <=> $a['friends_in_likers_count']);
        $coreUsers = array_values(array_filter($rows, fn(array $r) => $r['core_member']));

        $allIds = array_map(fn(array $r) => (int) $r['user_id'], $rows);
        $withDemographics = (bool) $this->option('demographics');
        $fields = $this->resolveProfileFields($withDemographics);
        if ($withDemographics) {
            $this->info('Получение профилей и демографических данных...');
        }

        $profiles = $usersService->getByIds($allIds, $fields, $delay);

        $profilesMeta = $this->buildProfilesMeta(count($allIds), count($profiles));
        $requested = $profilesMeta['requested'];
        $received = $profilesMeta['received'];
        if ($requested > 0 && $received / $requested < 0.9) {
            $this->warn("Получено профилей: {$received} из {$requested}. Демография может быть смещена.");
        }

        $rows = $this->enrichRowsWithProfiles($rows, $profiles);
        $coreUsers = array_values(array_filter($rows, fn(array $r) => $r['core_member']));

        $demographics = null;
        if ($withDemographics) {
            $demographics = $this->computeDemographics($rows, $profiles, $k);
        }

        // Сохраняем сегменты для продольного анализа (core-transitions)
        if (Schema::hasTable('user_post_segments')) {
            $segments = [];
            foreach ($rows as $r) {
                $seg = 'open';
                if (isset($r['core_member']) && $r['core_member']) {
                    $seg = 'core';
                } elseif (!$r['friends_data_available']) {
                    $seg = 'hidden';
                }
                $segments[] = [
                    'user_id' => $r['user_id'],
                    'owner_id' => $owner,
                    'post_id' => $postId,
                    'segment' => $seg,
                    'friends_in_likers_count' => $r['friends_in_likers_count'] ?? 0,
                ];
            }
            DB::table('user_post_segments')->upsert(
                $segments,
                ['owner_id', 'post_id', 'user_id'],
                ['segment', 'friends_in_likers_count']
            );
        }

        $result = [
            'post' => ['owner_id' => $owner, 'post_id' => $postId, 'views' => $postViews],
            'settings' => ['k' => $k, 'max_users' => $maxUsers, 'delay' => $delay],
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

        $outputPath = $this->option('output');
        if ($outputPath) {
            $saveFormat = $this->resolveFormatForOutput($format, (string) $outputPath);
            $content = $this->formatForFile($result, $saveFormat);
            $finalPath = $this->resolvePath((string) $outputPath);

            $dir = dirname($finalPath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $this->error("Не удалось создать директорию: {$dir}");
                return 1;
            }

            $bytes = file_put_contents($finalPath, $content);
            if ($bytes === false) {
                $this->error("Ошибка при сохранении файла: {$finalPath}");
                return 1;
            }

            $this->info("Результаты сохранены в файл: {$finalPath} ({$bytes} байт)");
            if ($format === 'table') {
                $this->displayTable($result);
            }
        } else {
            if ($format === 'table') {
                $this->displayTable($result);
            } else {
                $this->line($this->formatNonTable($result, $format));
            }
        }

        return 0;
    }

    private function getAllLikers(VkLikesService $likesService, string $owner, int $postId): ?array
    {
        $offset = 0;
        $count = 1000;
        $seen = [];
        $all = [];
        $totalCount = null;

        while (true) {
            $chunk = $likesService->getPostLikers($owner, $postId, $count, $offset);
            if ($chunk === null) {
                return null;
            }
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

        $this->table(['Показатель', 'Значение'], [
            ['Всего лайкнувших', $summary['total_likers'] ?? $summary['analyzed_likers']],
            ['Лайкнувших проанализировано', $summary['analyzed_likers']],
            ['Не обработано', $summary['omitted_likers'] ?? 0],
            ['Покрытие выборки', ($summary['sample_coverage_percent'] ?? 100) . '%'],
            ['Просмотров поста', $views],
            ['ERv (лайки / просмотры)', $erv],
            ['Пользователей в ядре', $summary['core_users_count']],
            ['Ошибок чтения друзей', $summary['friend_data_errors']],
        ]);

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
        $out .= "- Пользователей в ядре: `{$summary['core_users_count']}`\n\n";
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
        $out .= "Ошибок чтения друзей: {$summary['friend_data_errors']}\n\n";
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
            if ($r['core_member']) {
                $segments['core'][] = $uid;
            } elseif (!$r['friends_data_available']) {
                $segments['hidden'][] = $uid;
            } else {
                $segments['open'][] = $uid;
            }
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

