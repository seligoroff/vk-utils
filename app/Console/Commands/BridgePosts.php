<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BridgePosts extends Command
{
    protected $signature = 'vk:bridge-posts
                            {--owner= : ID владельца стены (обязательный)}
                            {--min-core-ratio=0.15 : Минимальная доля ядра среди лайкнувших (0-1)}
                            {--min-recruits=1 : Минимальное число новобранцев (open→core)}
                            {--top=5 : Сколько постов показать}';

    protected $description = 'Поиск постов-мостов: одновременно активируют ядро и привлекают новую аудиторию';

    public function handle(): int
    {
        if (!$this->option('owner')) {
            $this->error('Параметр --owner обязателен');
            return 1;
        }

        $ownerId = (string) $this->option('owner');
        $minCoreRatio = (float) $this->option('min-core-ratio');
        $minRecruits = (int) $this->option('min-recruits');
        $top = (int) $this->option('top');

        if (!Schema::hasTable('user_post_segments')) {
            $this->error('Таблица user_post_segments не найдена. Выполните миграции.');
            return 1;
        }

        // Статистика по сегментам для каждого поста
        $segStats = DB::table('user_post_segments')
            ->where('owner_id', $ownerId)
            ->selectRaw('post_id,
                COUNT(*) as total,
                SUM(CASE WHEN segment = "core" THEN 1 ELSE 0 END) as core_cnt,
                SUM(CASE WHEN segment = "open" THEN 1 ELSE 0 END) as open_cnt,
                SUM(CASE WHEN segment = "hidden" THEN 1 ELSE 0 END) as hidden_cnt')
            ->groupBy('post_id')
            ->get()
            ->keyBy('post_id');

        if ($segStats->isEmpty()) {
            $this->warn("Нет данных для owner_id={$ownerId}.");
            return 0;
        }

        // Новобранцы (open→core): считаем переходы МЕЖДУ соседними постами
        $postIds = $segStats->keys()->sort()->values()->toArray();
        $recruits = $this->countRecruits($ownerId, $postIds);

        // Даты постов
        $postDates = DB::table('vk_posts')
            ->where('owner_id', $ownerId)
            ->whereIn('post_id', $postIds)
            ->pluck('date', 'post_id');

        // Расчёт bridge score
        $rows = [];
        foreach ($postIds as $pid) {
            $s = $segStats[$pid];
            $coreRatio = $s->total > 0 ? $s->core_cnt / $s->total : 0;
            $openRatio = $s->total > 0 ? $s->open_cnt / $s->total : 0;
            $rec = $recruits[$pid] ?? 0;
            // Bridge = core_ratio × (1 + recruits): ядро даёт базу, новобранцы — множитель
            $bridgeScore = round($coreRatio * (1 + $rec), 3);

            if ($coreRatio >= $minCoreRatio && $rec >= $minRecruits) {
                $rows[] = [
                    'post_id' => $pid,
                    'date' => $postDates[$pid] ?? '?',
                    'likes' => $s->total,
                    'core_pct' => round($coreRatio * 100),
                    'open_pct' => round($openRatio * 100),
                    'recruits' => $rec,
                    'score' => round($bridgeScore, 3),
                ];
            }
        }

        // Сортировка по bridge score
        usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
        $rows = array_slice($rows, 0, $top);

        if (empty($rows)) {
            $this->warn("Посты-мосты не найдены. Попробуйте снизить --min-core-ratio или --min-recruits.");
            return 0;
        }

        // Вывод
        $this->info("Топ-{$top} постов-мостов для {$ownerId}");
        $this->newLine();

        $this->table(
            ['Post ID', 'Дата', 'Лайки', 'Core%', 'Open%', 'Новобр.', 'Bridge Score'],
            array_map(fn($r) => [
                $r['post_id'],
                $r['date'],
                $r['likes'],
                $r['core_pct'] . '%',
                $r['open_pct'] . '%',
                $r['recruits'],
                $r['score'],
            ], $rows)
        );

        $this->newLine();
        $this->line('Ссылки на оригиналы:');
        foreach ($rows as $r) {
            $url = "https://vk.com/wall{$ownerId}_{$r['post_id']}";
            $this->line("  - ID {$r['post_id']}: {$url}");
        }

        return 0;
    }

    private function countRecruits(string $ownerId, array $postIds): array
    {
        $recruits = [];
        $n = count($postIds);

        for ($i = 1; $i < $n; $i++) {
            $prevPid = $postIds[$i - 1];
            $currPid = $postIds[$i];

            // Пользователи, бывшие open в предыдущем и ставшие core в текущем
            $prevOpen = DB::table('user_post_segments')
                ->where('owner_id', $ownerId)
                ->where('post_id', $prevPid)
                ->where('segment', 'open')
                ->pluck('user_id')
                ->toArray();

            if (empty($prevOpen)) {
                continue;
            }

            $currCore = DB::table('user_post_segments')
                ->where('owner_id', $ownerId)
                ->where('post_id', $currPid)
                ->where('segment', 'core')
                ->whereIn('user_id', $prevOpen)
                ->count();

            $recruits[$prevPid] = ($recruits[$prevPid] ?? 0) + $currCore;
        }

        return $recruits;
    }
}
