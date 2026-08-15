<?php

namespace App\Console\Commands;

use App\Support\VkPostPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CoreTransitions extends Command
{
    protected $signature = 'vk:core-transitions
                             {--owner= : ID владельца стены (обязательный)}
                             {--window=10 : Размер скользящего окна для устойчивого ядра}
                             {--min-core-posts=2 : Минимальное число попаданий в ядро внутри окна}
                             {--from= : Нижняя граница дат постов (Y-m-d, опционально)}
                             {--to= : Правая граница дат постов (Y-m-d, не включается)}
                             {--posts-limit= : Максимальное число постов (по умолчанию все)}';

    protected $description = 'Продольный анализ переходов пользователей между сегментами';

    public function handle(): int
    {
        if (!$this->option('owner')) {
            $this->error('Параметр --owner обязателен');
            return 1;
        }

        $ownerId = (string) $this->option('owner');
        $window = (int) $this->option('window');
        $minCorePosts = (int) $this->option('min-core-posts');

        $from = $this->option('from');
        $to = $this->option('to');
        $postsLimit = $this->option('posts-limit');

        try {
            $fromTs = VkPostPeriod::fromInclusiveTimestamp($from !== null ? (string) $from : null);
            $toTs = VkPostPeriod::toExclusiveTimestamp($to !== null ? (string) $to : null);
        } catch (Throwable $e) {
            $this->error('Ошибка парсинга периода: '.$e->getMessage());
            return 1;
        }

        if ($fromTs !== null && $toTs !== null && $fromTs >= $toTs) {
            $this->error('Дата начала периода должна быть раньше даты окончания');
            return 1;
        }

        $limit = null;
        if ($postsLimit !== null && $postsLimit !== '') {
            $limit = (int) $postsLimit;
            if ($limit < 1) {
                $this->error('Параметр --posts-limit должен быть больше нуля');
                return 1;
            }
        }

        $postIdsQuery = DB::table('user_post_segments as s')
            ->join('vk_posts as p', function ($join) {
                $join->on('s.owner_id', '=', 'p.owner_id')
                     ->on('s.post_id', '=', 'p.post_id');
            })
            ->where('s.owner_id', $ownerId)
            ->select('p.post_id')
            ->groupBy('p.post_id', 'p.date')
            ->orderBy('p.date')
            ->orderBy('p.post_id');

        if ($fromTs !== null) {
            $postIdsQuery->where('p.timestamp', '>=', $fromTs);
        }
        if ($toTs !== null) {
            $postIdsQuery->where('p.timestamp', '<', $toTs);
        }
        if ($limit !== null) {
            $postIdsQuery->limit($limit);
        }

        $limitedPostIds = $postIdsQuery->pluck('post_id');

        $rows = collect();
        if ($limitedPostIds->isNotEmpty()) {
            $rows = DB::table('user_post_segments as s')
                ->join('vk_posts as p', function ($join) {
                    $join->on('s.owner_id', '=', 'p.owner_id')
                         ->on('s.post_id', '=', 'p.post_id');
                })
                ->where('s.owner_id', $ownerId)
                ->whereIn('s.post_id', $limitedPostIds)
                ->select('s.user_id', 's.post_id', 's.segment', 'p.date')
                ->orderBy('p.date')
                ->orderBy('s.post_id')
                ->get();
        }

        if ($rows->isEmpty()) {
            $this->warn("Нет данных для owner_id={$ownerId}. Запустите vk:likers-core для нескольких постов этой группы.");
            return 0;
        }

        // Группируем по post_id, сохраняя порядок постов по дате
        $posts = [];
        foreach ($rows as $row) {
            $posts[$row->post_id]['date'] = $row->date;
            $posts[$row->post_id]['users'][$row->user_id] = $row->segment;
        }

        $postIds = array_keys($posts);
        $postCount = count($postIds);
        $periodInfo = '';
        if ($from || $to) {
            $periodInfo = ' (период: ' . ($from ?: '...') . ' – ' . ($to ?: '...') . ')';
        }
        $this->info("Постов проанализировано: {$postCount}{$periodInfo}");

        if ($postCount < 2) {
            $this->warn('Нужно минимум 2 поста для построения переходов.');
            return 0;
        }

        // Матрица переходов (между соседними постами)
        $transitions = $this->buildTransitions($posts, $postIds);

        // Устойчивое ядро (скользящее окно)
        $stableCore = $this->buildStableCore($posts, $postIds, $window, $minCorePosts);

        // Вывод
        $this->displayTransitions($transitions);
        $this->displayStableCore($stableCore, $window, $minCorePosts);

        return 0;
    }

    private function buildTransitions(array $posts, array $postIds): array
    {
        $t = [
            'core_to_core' => 0, 'core_to_open' => 0, 'core_to_hidden' => 0, 'core_to_absent' => 0,
            'open_to_core' => 0, 'open_to_open' => 0, 'open_to_hidden' => 0, 'open_to_absent' => 0,
            'hidden_to_core' => 0, 'hidden_to_open' => 0, 'hidden_to_hidden' => 0, 'hidden_to_absent' => 0,
        ];

        for ($i = 0; $i < count($postIds) - 1; $i++) {
            $prev = $posts[$postIds[$i]]['users'];
            $next = $posts[$postIds[$i + 1]]['users'];

            // Все пользователи, которые были в предыдущем посте
            foreach ($prev as $uid => $prevSeg) {
                $nextSeg = $next[$uid] ?? 'absent';
                $key = "{$prevSeg}_to_{$nextSeg}";
                $t[$key] = ($t[$key] ?? 0) + 1;
            }

            // Новые пользователи в следующем посте (отсутствовали в предыдущем)
            foreach ($next as $uid => $nextSeg) {
                if (!isset($prev[$uid])) {
                    $key = "absent_to_{$nextSeg}";
                    $t[$key] = ($t[$key] ?? 0) + 1;
                }
            }
        }

        return $t;
    }

    private function buildStableCore(array $posts, array $postIds, int $window, int $minCorePosts): array
    {
        $result = [];
        $n = count($postIds);

        for ($end = $window - 1; $end < $n; $end++) {
            $start = max(0, $end - $window + 1);
            $windowIds = array_slice($postIds, $start, $end - $start + 1);

            // Считаем, сколько раз каждый user_id попал в ядро внутри окна
            $coreCounts = [];
            foreach ($windowIds as $pid) {
                foreach (($posts[$pid]['users'] ?? []) as $uid => $seg) {
                    if ($seg === 'core') {
                        $coreCounts[$uid] = ($coreCounts[$uid] ?? 0) + 1;
                    }
                }
            }

            $stableIds = [];
            foreach ($coreCounts as $uid => $cnt) {
                if ($cnt >= $minCorePosts) {
                    $stableIds[] = $uid;
                }
            }

            $result[] = [
                'last_post_id' => $postIds[$end],
                'window_size' => count($windowIds),
                'stable_core_count' => count($stableIds),
                'stable_core_ids' => $stableIds,
            ];
        }

        return $result;
    }

    private function displayTransitions(array $t): void
    {
        $this->newLine();
        $this->info('=== Матрица переходов (между соседними постами) ===');
        $this->newLine();

        $this->table(
            ['Переход', 'Количество'],
            [
                ['core → core', $t['core_to_core']],
                ['core → open', $t['core_to_open']],
                ['core → hidden', $t['core_to_hidden']],
                ['core → absent', $t['core_to_absent']],
                ['open → core', $t['open_to_core']],
                ['open → open', $t['open_to_open']],
                ['open → hidden', $t['open_to_hidden']],
                ['open → absent', $t['open_to_absent']],
                ['hidden → core', $t['hidden_to_core']],
                ['hidden → open', $t['hidden_to_open']],
                ['hidden → hidden', $t['hidden_to_hidden']],
                ['hidden → absent', $t['hidden_to_absent']],
            ]
        );

        // Сводка
        $recruited = $t['open_to_core'] + $t['hidden_to_core'];
        $retained = $t['core_to_core'];
        $lost = $t['core_to_open'] + $t['core_to_hidden'] + $t['core_to_absent'];
        $this->line("Новобранцев в ядро: {$recruited} | Удержано в ядре: {$retained} | Покинуло ядро: {$lost}");
    }

    private function displayStableCore(array $stableCore, int $window, int $minCorePosts): void
    {
        $this->newLine();
        $this->info("=== Устойчивое ядро (окно={$window}, min={$minCorePosts}) ===");
        $this->newLine();

        $rows = [];
        foreach ($stableCore as $entry) {
            $rows[] = [
                $entry['last_post_id'],
                $entry['window_size'],
                $entry['stable_core_count'],
            ];
        }

        $this->table(
            ['Последний post_id', 'Постов в окне', 'Устойчивое ядро (чел.)'],
            $rows
        );

        if (!empty($stableCore)) {
            $last = end($stableCore);
            $this->line("Последнее окно: post_id={$last['last_post_id']}, устойчивое ядро: {$last['stable_core_count']} чел.");
        }
    }
}
