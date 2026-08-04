<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EarlyPosts extends Command
{
    protected $signature = 'vk:early-posts
                            {--owner= : ID владельца стены (обязательный, отрицательное число для групп)}
                            {--max-reposts=1 : Максимальное количество репостов (по умолчанию 1)}
                            {--from= : Дата начала периода (опционально, формат: YYYY-MM-DD)}
                            {--to= : Дата окончания периода (опционально, формат: YYYY-MM-DD)}
                            {--limit=10 : Количество постов (по умолчанию 10)}
                            {--offset=0 : Смещение (для пагинации, по умолчанию 0)}
                            {--format=table : Формат вывода: table, json, csv, markdown}
                            {--output= : Путь к файлу для сохранения результатов}';

    protected $description = 'Поиск ранних постов с низким количеством репостов';

    public function handle(): int
    {
        if (!$this->option('owner')) {
            $this->error('Параметр --owner обязателен');
            return 1;
        }

        $ownerId = (string) $this->option('owner');
        $maxReposts = (int) $this->option('max-reposts');
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');

        if (!Schema::hasTable('vk_posts')) {
            $this->error('Таблица vk_posts не найдена. Выполните миграции: php artisan migrate');
            return 1;
        }

        $query = DB::table('vk_posts')
            ->where('owner_id', $ownerId)
            ->where('reposts', '<=', $maxReposts)
            ->where('text', '!=', '')
            ->orderBy('date', 'asc')
            ->skip($offset)
            ->limit($limit);

        if ($from = $this->option('from')) {
            $query->where('date', '>=', $from . ' 00:00:00');
        }

        if ($to = $this->option('to')) {
            $query->where('date', '<=', $to . ' 23:59:59');
        }

        $posts = $query->get([
            'id', 'date', 'text', 'likes', 'reposts', 'comments', 'views',
            'url',
        ]);

        if ($posts->isEmpty()) {
            $this->warn('Посты не найдены');
            return 0;
        }

        $format = $this->option('format');

        if ($format === 'table') {
            $this->table(
                ['ID', 'Дата', 'Текст', 'Лайки', 'Репосты', '⇨', 'Просмотры', 'URL'],
                $posts->map(fn($p) => [
                    $p->id,
                    $p->date,
                    mb_substr(str_replace("\n", ' ', $p->text ?? ''), 0, 80),
                    $p->likes,
                    $p->reposts,
                    $p->comments,
                    $p->views,
                    $p->url,
                ])
            );
        } elseif ($format === 'json') {
            $output = json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $this->writeOutput($output, 'json');
        } elseif ($format === 'csv') {
            $csv = "id,дата,текст,лайки,репосты,комментарии,просмотры,url\n";
            foreach ($posts as $p) {
                $text = str_replace('"', '""', str_replace("\n", ' ', $p->text ?? ''));
                $csv .= "{$p->id},{$p->date},\"{$text}\",{$p->likes},{$p->reposts},{$p->comments},{$p->views},{$p->url}\n";
            }
            $this->writeOutput($csv, 'csv');
        } elseif ($format === 'markdown') {
            $md = "| ID | Дата | Текст | Лайки | Репосты | ⇨ | Просмотры | URL |\n";
            $md .= "|---|--|---|---:|---:|---:|---:|---|\n";
            foreach ($posts as $p) {
                $text = mb_substr(str_replace("\n", ' ', $p->text ?? ''), 0, 60);
                $md .= "| {$p->id} | {$p->date} | {$text} | {$p->likes} | {$p->reposts} | {$p->comments} | {$p->views} | {$p->url} |\n";
            }
            $this->writeOutput($md, 'md');
        }

        return 0;
    }

    private function writeOutput(string $content, string $ext): void
    {
        if ($path = $this->option('output')) {
            file_put_contents($path, $content);
            $this->info("Сохранено: {$path}");
        } else {
            $this->line($content);
        }
    }
}
