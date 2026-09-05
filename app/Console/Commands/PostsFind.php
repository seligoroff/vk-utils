<?php

namespace App\Console\Commands;

use App\Support\VkPostPeriod;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PostsFind extends Command
{
    protected $signature = 'vk:posts-find
                            {--db-id= : Внутренний первичный ключ записи vk_posts.id}
                            {--post-id= : Идентификатор поста VK}
                            {--owner= : ID владельца стены; отрицательный для группы}
                            {--text= : Буквальное регистронезависимое вхождение в текст}
                            {--from= : Начало периода}
                            {--to= : Правая граница периода, не включается}
                            {--limit=50 : Размер страницы}
                            {--offset=0 : Смещение}
                            {--order=desc : Порядок по дате: asc|desc}
                            {--format=table : Формат вывода: table, json, csv, markdown}
                            {--output= : Файл для сохранения результата}
                            {--full-text : Не сокращать текст в table и markdown}';

    protected $description = 'Поиск сохранённых постов в vk_posts по id, post_id или тексту (без VK API)';

    public function handle(): int
    {
        $dbId = trim((string) $this->option('db-id'));
        $postId = trim((string) $this->option('post-id'));
        $ownerId = trim((string) $this->option('owner'));
        $text = (string) $this->option('text');
        $from = trim((string) $this->option('from'));
        $to = $this->option('to');
        $to = $to !== null ? trim((string) $to) : '';
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $order = strtolower((string) $this->option('order'));
        $format = strtolower((string) $this->option('format'));

        if ($dbId === '' && $postId === '' && trim($text) === '') {
            $this->error('Нужен хотя бы один селективный фильтр: --db-id, --post-id или --text');

            return self::FAILURE;
        }

        if ($dbId !== '' && ! ctype_digit($dbId)) {
            $this->error('Параметр --db-id должен быть положительным целым числом');

            return self::FAILURE;
        }

        if ($postId !== '' && ! ctype_digit($postId)) {
            $this->error('Параметр --post-id должен быть положительным целым числом');

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

        $period = null;
        if ($from !== '' || $to !== '') {
            try {
                if ($from !== '' && $to !== '') {
                    $period = VkPostPeriod::fromCommandOptions($from, $to);
                } elseif ($from !== '') {
                    $period = VkPostPeriod::fromCommandOptions($from, null);
                } else {
                    $toExclusive = VkPostPeriod::toExclusiveTimestamp($to);
                    if ($toExclusive === null) {
                        throw new \InvalidArgumentException('Не удалось разобрать --to');
                    }
                    $period = new VkPostPeriod(0, $toExclusive);
                }
            } catch (Throwable $e) {
                $this->error('Ошибка парсинга периода: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        if (! Schema::hasTable('vk_posts')) {
            $this->error('Таблица vk_posts не найдена. Выполните миграции: php artisan migrate');

            return self::FAILURE;
        }

        $warnings = [];

        try {
            $this->ensureSqliteUnicodeLower();
            $query = DB::table('vk_posts');

            if ($dbId !== '') {
                $query->where('id', (int) $dbId);
            }
            if ($postId !== '') {
                $query->where('post_id', (int) $postId);
            }
            if ($ownerId !== '') {
                $query->where('owner_id', $ownerId);
            }
            if (trim($text) !== '') {
                $this->applyLiteralTextFilter($query, trim($text));
            }
            if ($period !== null) {
                $query
                    ->where('timestamp', '>=', $period->fromInclusive)
                    ->where('timestamp', '<', $period->toExclusive);
            }

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
        } catch (Throwable $e) {
            $this->error('Ошибка чтения vk_posts: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($postId !== '' && $ownerId === '' && $total > 1) {
            $warnings[] = "Найдено несколько записей с post_id={$postId} у разных владельцев. Уточните --owner.";
        }

        $filters = [
            'db_id' => $dbId !== '' ? (int) $dbId : null,
            'post_id' => $postId !== '' ? (int) $postId : null,
            'owner_id' => $ownerId !== '' ? $ownerId : null,
            'text' => trim($text) !== '' ? trim($text) : null,
            'from' => $period?->fromLabel(),
            'to_exclusive' => $period !== null
                ? Carbon::createFromTimestamp($period->toExclusive)->format('Y-m-d H:i:s')
                : null,
        ];

        $result = [
            'filters' => $filters,
            'warnings' => $warnings,
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

    private function ensureSqliteUnicodeLower(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        // PDO может пересоздаваться между тестами/запросами — регистрируем каждый раз.
        DB::connection()->getPdo()->sqliteCreateFunction(
            'mb_lower',
            static fn ($value) => mb_strtolower((string) $value, 'UTF-8'),
            1
        );
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function applyLiteralTextFilter($query, string $text): void
    {
        $needle = mb_strtolower($text, 'UTF-8');
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle);
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $query->whereRaw("mb_lower(text) LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);

            return;
        }

        // MySQL/MariaDB: ESCAPE '\\' must be written as four backslashes in PHP.
        $query->whereRaw("LOWER(text) LIKE ? ESCAPE '\\\\'", ['%'.$escaped.'%']);
    }

    private function displayTable(array $result, Collection $posts): void
    {
        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $pagination = $result['pagination'];
        $this->info('Поиск в vk_posts');
        $this->line(
            "Всего: {$pagination['total']}; показано: {$pagination['returned']}; offset: {$pagination['offset']}"
        );

        if ($posts->isEmpty()) {
            $this->warn('Совпадений нет');

            return;
        }

        $fullText = (bool) $this->option('full-text');
        $this->table(
            ['ID в БД', 'ID VK', 'owner_id', 'Дата', 'Текст', 'Лайки', 'Репосты', 'Комментарии', 'Просмотры'],
            $posts->map(fn (array $post) => [
                $post['id'],
                $post['post_id'],
                $post['owner_id'],
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
        $pagination = $result['pagination'];
        $fullText = (bool) $this->option('full-text');
        $content = "# Поиск постов в vk_posts\n\n";
        $content .= "Всего: {$pagination['total']}, показано: {$pagination['returned']}.\n";

        if ($result['posts'] === []) {
            $content .= "\nСовпадений нет.\n";

            return $content;
        }

        foreach ($result['posts'] as $post) {
            $text = $this->displayText($post['text'], $fullText ? null : 500);
            $content .= "\n## {$post['date']} — owner={$post['owner_id']} post_id={$post['post_id']} (db_id={$post['id']})\n\n";
            $content .= ($text !== '' ? $text : '_Без текста_')."\n\n";
            $content .= "Лайки: {$post['likes']}; репосты: {$post['reposts']}; ";
            $content .= "комментарии: {$post['comments']}; просмотры: ".($post['views'] ?? '—').".\n\n";
            $content .= "Оригинал: {$post['url']}\n";
        }

        return $content;
    }

    private function formatPlainText(array $result): string
    {
        $pagination = $result['pagination'];
        $content = "Поиск в vk_posts\n";
        $content .= "Всего: {$pagination['total']}; показано: {$pagination['returned']}\n";

        if ($result['posts'] === []) {
            $content .= "Совпадений нет\n";

            return $content;
        }

        foreach ($result['posts'] as $post) {
            $content .= "\n{$post['date']} | db_id={$post['id']} | owner={$post['owner_id']} | post_id={$post['post_id']} | {$post['url']}\n";
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
