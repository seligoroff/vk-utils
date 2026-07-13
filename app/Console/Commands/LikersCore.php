<?php

namespace App\Console\Commands;

use App\Services\VkApi\VkFriendsService;
use App\Services\VkApi\VkLikesService;
use App\Services\VkApi\VkUsersService;
use Illuminate\Console\Command;

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

        if (count($likers) > $maxUsers) {
            $this->warn("Лайкнувших: " . count($likers) . ". Будут обработаны первые {$maxUsers} пользователей.");
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

        if ($verboseErrors && !empty($errorStats)) {
            $this->warn('Сводка ошибок friends.get:');
            $errorRows = [];
            foreach ($errorStats as $error => $meta) {
                $errorRows[] = [
                    $error,
                    $meta['count'],
                    implode(', ', $meta['users']),
                ];
            }
            usort($errorRows, fn(array $a, array $b) => $b[1] <=> $a[1]);
            $this->table(['Ошибка', 'Количество', 'Примеры user_id'], $errorRows);
            $this->newLine();
        }

        usort($rows, fn(array $a, array $b) => $b['friends_in_likers_count'] <=> $a['friends_in_likers_count']);
        $coreUsers = array_values(array_filter($rows, fn(array $r) => $r['core_member']));

        $allIds = array_map(fn(array $r) => (int) $r['user_id'], $rows);
        $profiles = $usersService->getByIds($allIds, ['screen_name']);
        $rows = $this->enrichRowsWithProfiles($rows, $profiles);
        $coreUsers = array_values(array_filter($rows, fn(array $r) => $r['core_member']));

        $result = [
            'post' => ['owner_id' => $owner, 'post_id' => $postId],
            'settings' => ['k' => $k, 'max_users' => $maxUsers, 'delay' => $delay],
            'summary' => [
                'analyzed_likers' => count($rows),
                'core_users_count' => count($coreUsers),
                'friend_data_errors' => $friendErrors,
                'friend_error_types' => $this->flattenErrorStats($errorStats),
            ],
            'core_users' => $coreUsers,
            'users' => $rows,
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

        $this->info("Пост: {$post['owner_id']}_{$post['post_id']}");
        $this->info("Порог ядра k: {$k}");
        $this->table(['Показатель', 'Значение'], [
            ['Лайкнувших проанализировано', $summary['analyzed_likers']],
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
        $out = "# Ядро лайкнувших\n\n";
        $out .= "- Пост: `{$result['post']['owner_id']}_{$result['post']['post_id']}`\n";
        $out .= "- Порог k: `{$result['settings']['k']}`\n";
        $out .= "- Лайкнувших проанализировано: `{$result['summary']['analyzed_likers']}`\n";
        $out .= "- Пользователей в ядре: `{$result['summary']['core_users_count']}`\n\n";
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
        $out = "Ядро лайкнувших\n";
        $out .= "Пост: {$result['post']['owner_id']}_{$result['post']['post_id']}\n";
        $out .= "Порог k: {$result['settings']['k']}\n";
        $out .= "Лайкнувших проанализировано: {$result['summary']['analyzed_likers']}\n";
        $out .= "Пользователей в ядре: {$result['summary']['core_users_count']}\n";
        $out .= "Ошибок чтения друзей: {$result['summary']['friend_data_errors']}\n\n";
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
        if (mb_strlen($message) > 180) {
            $message = mb_substr($message, 0, 180) . '...';
        }

        return $message;
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
}

