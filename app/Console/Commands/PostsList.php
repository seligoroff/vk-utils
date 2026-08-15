<?php

namespace App\Console\Commands;

use App\Support\VkPostPeriod;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PostsList extends Command
{
    protected $signature = 'vk:posts-list
                            {--owner= : ID владельца стены (обязательный, отрицательное число для групп)}
                            {--from= : Начало периода (обязательно)}
                            {--to= : Конец периода, не включается (по умолчанию — текущая дата и время)}
                            {--limit=50 : Количество постов на странице}
                            {--offset=0 : Смещение для пагинации}
                            {--order=asc : Порядок по дате: asc или desc}
                            {--format=table : Формат вывода: table, json, csv, markdown}
                            {--output= : Путь к файлу для сохранения результата}
                            {--full-text : Не сокращать текст в table и markdown}';

    protected $description = 'Просмотр сохранённых постов владельца за период из таблицы vk_posts';

    public function handle(): int
    {
        $ownerId = trim((string) $this->option('owner'));
        $from = trim((string) $this->option('from'));
        $to = $this->option('to');
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $order = strtolower((string) $this->option('order'));
        $format = strtolower((string) $this->option('format'));

        if ($ownerId === '') {
            $this->error('Параметр --owner обязателен');

            return self::FAILURE;
        }

        if ($from === '') {
            $this->error('Параметр --from обязателен');

            return self::FAILURE;
        }

        if ($limit < 1) {
            $this->error('Параметр --limit должен быть больше нуля');

            return self::FAILURE;
        }

        if ($offset < 0) {
            $this->error('Параметр --offset не может быть отрицательным');

            return self::FAILURE;
        }

        if (! in_array($order, ['asc', 'desc'], true)) {
            $this->error('Неверный порядок. Допустимые значения: asc, desc');

            return self::FAILURE;
        }

        if (! in_array($format, ['table', 'json', 'csv', 'markdown'], true)) {
            $this->error('Неверный формат. Допустимые значения: table, json, csv, markdown');

            return self::FAILURE;
        }

        try {
            $period = VkPostPeriod::fromCommandOptions(
                $from,
                $to !== null ? (string) $to : null
            );
        } catch (Throwable $e) {
            $this->error('Ошибка парсинга периода: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! Schema::hasTable('vk_posts')) {
            $this->error('Таблица vk_posts не найдена. Выполните миграции: php artisan migrate');

            return self::FAILURE;
        }

        $query = DB::table('vk_posts')
            ->where('owner_id', $ownerId)
            ->where('timestamp', '>=', $period->fromInclusive)
            ->where('timestamp', '<', $period->toExclusive);

        $total = (clone $query)->count();
        $columns = [
            'id',
            'post_id',
            'owner_id',
            'date',
            'text',
            'likes',
            'reposts',
            'comments',
            'url',
        ];

        if (Schema::hasColumn('vk_posts', 'views')) {
            $columns[] = 'views';
        }

        $posts = $query
            ->orderBy('timestamp', $order)
            ->orderBy('post_id', $order)
            ->offset($offset)
            ->limit($limit)
            ->get($columns)
            ->map(function ($post) {
                $row = (array) $post;
                $row['views'] = $row['views'] ?? null;

                return $row;
            });

        $result = [
            'owner_id' => $ownerId,
            'period' => [
                'from' => $period->fromLabel(),
                'to_exclusive' => Carbon::createFromTimestamp($period->toExclusive)->format('Y-m-d H:i:s'),
            ],
            'pagination' => [
                'total' => $total,
                'offset' => $offset,
                'limit' => $limit,
                'returned' => $posts->count(),
                'has_more' => $offset + $posts->count() < $total,
            ],
            'posts' => $posts->values()->all(),
        ];

        $outputPath = $this->option('output');
        if ($outputPath) {
            $content = $this->formatOutput($result, $format);

            if (! $this->writeToFile((string) $outputPath, $content)) {
                return self::FAILURE;
            }

            $this->info("Сохранено: {$outputPath}");

            return self::SUCCESS;
        }

        if ($format === 'table') {
            $this->displayTable($result, $posts);

            return self::SUCCESS;
        }

        $this->line($this->formatOutput($result, $format));

        return self::SUCCESS;
    }

    private function displayTable(array $result, Collection $posts): void
    {
        $period = $result['period'];
        $pagination = $result['pagination'];

        $this->info("Посты owner_id={$result['owner_id']} за период [{$period['from']}, {$period['to_exclusive']})");
        $this->line(
            "Всего: {$pagination['total']}; показано: {$pagination['returned']}; offset: {$pagination['offset']}"
        );

        if ($posts->isEmpty()) {
            $this->warn('Посты не найдены');

            return;
        }

        $fullText = (bool) $this->option('full-text');
        $this->table(
            ['ID в БД', 'ID VK', 'Дата', 'Текст', 'Лайки', 'Репосты', 'Комментарии', 'Просмотры'],
            $posts->map(fn (array $post) => [
                $post['id'],
                $post['post_id'],
                $post['date'],
                $this->displayText($post['text'], $fullText ? null : 180),
                $post['likes'],
                $post['reposts'],
                $post['comments'],
                $post['views'] ?? '—',
            ])->all()
        );

        $this->newLine();
        $this->line('<info>Ссылки на оригиналы:</info>');
        foreach ($posts as $post) {
            $this->line("- {$post['id']}: {$post['url']}");
        }
    }

    private function formatOutput(array $result, string $format): string
    {
        if ($format === 'json') {
            return (string) json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
        }

        if ($format === 'csv') {
            return $this->formatCsv($result['posts']);
        }

        if ($format === 'markdown') {
            return $this->formatMarkdown($result);
        }

        return $this->formatPlainText($result);
    }

    private function formatCsv(array $posts): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['id', 'post_id', 'owner_id', 'date', 'text', 'likes', 'reposts', 'comments', 'views', 'url']);

        foreach ($posts as $post) {
            fputcsv($stream, [
                $post['id'],
                $post['post_id'],
                $post['owner_id'],
                $post['date'],
                $post['text'],
                $post['likes'],
                $post['reposts'],
                $post['comments'],
                $post['views'],
                $post['url'],
            ]);
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return (string) $content;
    }

    private function formatMarkdown(array $result): string
    {
        $period = $result['period'];
        $pagination = $result['pagination'];
        $fullText = (bool) $this->option('full-text');
        $content = "# Посты owner_id={$result['owner_id']}\n\n";
        $content .= "Период: `[{$period['from']}, {$period['to_exclusive']})`. ";
        $content .= "Всего: {$pagination['total']}, показано: {$pagination['returned']}.\n";

        foreach ($result['posts'] as $post) {
            $text = $this->displayText($post['text'], $fullText ? null : 500);
            $content .= "\n## {$post['date']} — post_id={$post['post_id']}\n\n";
            $content .= ($text !== '' ? $text : '_Без текста_')."\n\n";
            $content .= "Лайки: {$post['likes']}; репосты: {$post['reposts']}; ";
            $content .= "комментарии: {$post['comments']}; просмотры: ".($post['views'] ?? '—').".\n\n";
            $content .= "Оригинал: {$post['url']}\n";
        }

        return $content;
    }

    private function formatPlainText(array $result): string
    {
        $period = $result['period'];
        $pagination = $result['pagination'];
        $content = "Посты owner_id={$result['owner_id']} за период [{$period['from']}, {$period['to_exclusive']})\n";
        $content .= "Всего: {$pagination['total']}; показано: {$pagination['returned']}\n";

        foreach ($result['posts'] as $post) {
            $content .= "\n{$post['date']} | post_id={$post['post_id']} | {$post['url']}\n";
            $content .= $this->displayText($post['text'], null)."\n";
            $content .= "Лайки: {$post['likes']}; репосты: {$post['reposts']}; ";
            $content .= "комментарии: {$post['comments']}; просмотры: ".($post['views'] ?? '—')."\n";
        }

        return $content;
    }

    private function displayText(?string $text, ?int $limit): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        if ($limit !== null && mb_strlen($text) > $limit) {
            return rtrim(mb_substr($text, 0, $limit - 1)).'…';
        }

        return $text;
    }

    private function writeToFile(string $path, string $content): bool
    {
        $directory = dirname($path);
        if ($directory !== '.' && ! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            $this->error("Не удалось создать директорию: {$directory}");

            return false;
        }

        if (file_put_contents($path, $content) === false) {
            $this->error("Не удалось сохранить файл: {$path}");

            return false;
        }

        return true;
    }
}
