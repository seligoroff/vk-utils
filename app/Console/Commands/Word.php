<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\VkApi\VkWallService;

class Word extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:word {word : Слово для поиска}
                            {--owner= : ID владельца (группа или пользователь), например -12345678 (обязательный)}
                            {--from= : Дата начала периода (формат: YYYY-MM-DD, обязательный)}
                            {--to= : Дата окончания периода (формат: YYYY-MM-DD, по умолчанию текущая дата)}
                            {--format=table : Формат вывода: table, json, csv, markdown}
                            {--output= : Путь к файлу для сохранения результатов}
                            {--db : Поиск в таблице vk_posts вместо запросов к API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Поиск постов по слову за указанный период с выводом статистики';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Валидация обязательных параметров
        $word = $this->argument('word');
        if (empty($word)) {
            $this->error('Слово для поиска обязательно');
            return 1;
        }

        if (!$this->option('owner')) {
            $this->error('Параметр --owner обязателен');
            return 1;
        }

        if (!$this->option('from')) {
            $this->error('Параметр --from обязателен');
            return 1;
        }

        // Парсинг дат
        try {
            $fromTimestamp = $this->parseDate($this->option('from'));
            $toTimestamp = $this->option('to') 
                ? $this->parseDate($this->option('to'))
                : time();
        } catch (\Exception $e) {
            $this->error('Ошибка парсинга даты: ' . $e->getMessage());
            return 1;
        }

        if ($fromTimestamp > $toTimestamp) {
            $this->error('Дата начала периода не может быть больше даты окончания');
            return 1;
        }

        // Валидация формата
        $format = $this->option('format');
        if (!in_array($format, ['table', 'json', 'csv', 'markdown'])) {
            $this->error('Неверный формат. Допустимые значения: table, json, csv, markdown');
            return 1;
        }

        // Получение постов
        if ($this->option('db')) {
            // Поиск в базе данных
            $this->info("Поиск постов со словом '{$word}' в базе данных с {$this->formatDate($fromTimestamp)} по {$this->formatDate($toTimestamp)}...");
            
            try {
                $matchedPosts = $this->getPostsFromDatabase($word, $this->option('owner'), $fromTimestamp, $toTimestamp);
            } catch (\Throwable $e) {
                $this->error('Ошибка при получении постов из базы данных: ' . $e->getMessage());
                return 1;
            }
        } else {
            // Поиск через API
            $this->info("Поиск постов со словом '{$word}' через API с {$this->formatDate($fromTimestamp)} по {$this->formatDate($toTimestamp)}...");
            
            $wallService = app()->make(VkWallService::class);
            $wallService->setOwner($this->option('owner'));

            $matchedPosts = [];
            $offset = 0;
            $progressBar = null;

            try {
                while (true) {
                    $posts = $wallService->getPosts(100, $offset);
                    
                    if (empty($posts)) {
                        break;
                    }

                    // Инициализация progress bar при первом запросе
                    if ($progressBar === null && count($posts) > 0) {
                        $progressBar = $this->output->createProgressBar();
                        $progressBar->setFormat(' %current% постов обработано');
                        $progressBar->start();
                    }

                    $shouldBreak = false;
                    foreach ($posts as $post) {
                        // Фильтрация по дате
                        if (!isset($post->date)) {
                            continue;
                        }
                        
                        // Если пост старше начала периода, прекращаем обработку
                        if ($post->date < $fromTimestamp) {
                            $shouldBreak = true;
                            break;
                        }
                        
                        if ($post->date > $toTimestamp) {
                            continue;
                        }

                        // Фильтрация по тексту (поиск слова, регистр не учитывается)
                        $postText = $post->text ?? '';
                        if (!preg_match('/' . preg_quote($word, '/') . '/iu', $postText)) {
                            continue;
                        }

                        $matchedPosts[] = $post;
                    }

                    if ($progressBar) {
                        $progressBar->advance(count($posts));
                    }

                    // Если нужно прервать (посты старше начала периода)
                    if ($shouldBreak) {
                        break;
                    }

                    // Если получили меньше постов, чем запрашивали, значит это последняя страница
                    if (count($posts) < 100) {
                        break;
                    }

                    $offset += 100;
                    usleep(300000); // 0.3 секунды задержка между запросами
                }

                if ($progressBar) {
                    $progressBar->finish();
                    $this->newLine();
                }

            } catch (\Throwable $e) {
                if ($progressBar) {
                    $progressBar->finish();
                    $this->newLine();
                }
                $this->error('Ошибка при получении постов: ' . $e->getMessage());
                return 1;
            }
        }

        // Подсчет статистики
        $statistics = $this->calculateStatistics($matchedPosts);

        // Формирование результата
        $result = [
            'word' => $word,
            'period' => [
                'from' => $this->formatDate($fromTimestamp),
                'to' => $this->formatDate($toTimestamp),
            ],
            'owner_id' => $this->option('owner'),
            'statistics' => $statistics,
        ];

        // Вывод результатов
        if ($this->option('output')) {
            $outputPath = $this->option('output');
            $fileOutput = $this->formatOutput($result, $format);
            
            // Создаем директорию, если нужно
            $directory = dirname($outputPath);
            if ($directory !== '.' && !is_dir($directory)) {
                if (!mkdir($directory, 0755, true)) {
                    $this->error("Не удалось создать директорию: {$directory}");
                    return 1;
                }
            }
            
            // Сохраняем в файл
            $bytesWritten = file_put_contents($outputPath, $fileOutput);
            if ($bytesWritten === false) {
                $this->error("Ошибка при сохранении файла: {$outputPath}");
                return 1;
            }
            
            $this->info("Результаты сохранены в файл: {$outputPath} ({$bytesWritten} байт)");
            
            // Для формата table и markdown всегда выводим таблицу в консоль
            if ($format === 'table' || $format === 'markdown') {
                $this->displayTable($result);
            }
        } else {
            // Вывод в консоль
            if ($format === 'table') {
                $this->displayTable($result);
            } else {
                $output = $this->formatOutput($result, $format);
                $this->line($output);
            }
        }

        return 0;
    }

    /**
     * Получение постов из базы данных
     *
     * @param string $word
     * @param string $ownerId
     * @param int $fromTimestamp
     * @param int $toTimestamp
     * @return array
     */
    private function getPostsFromDatabase(string $word, string $ownerId, int $fromTimestamp, int $toTimestamp): array
    {
        if (!Schema::hasTable('vk_posts')) {
            $this->error('Таблица vk_posts не найдена в базе данных.');
            $this->info('Выполните миграции: php artisan migrate');
            return [];
        }

        $fromDate = Carbon::createFromTimestamp($fromTimestamp)->toDateTimeString();
        $toDate = Carbon::createFromTimestamp($toTimestamp)->toDateTimeString();

        // Поиск с учетом регистра (используем LOWER для регистронезависимого поиска)
        $posts = DB::table('vk_posts')
            ->where('owner_id', $ownerId)
            ->whereBetween('date', [$fromDate, $toDate])
            ->whereRaw('LOWER(text) LIKE ?', ['%' . mb_strtolower($word, 'UTF-8') . '%'])
            ->orderBy('date', 'desc')
            ->get();

        // Конвертируем записи из БД в объекты, похожие на API-ответ
        $matchedPosts = [];
        foreach ($posts as $post) {
            // Преобразуем в объект с структурой, похожей на API
            $postObj = new \stdClass();
            $postObj->id = $post->post_id;
            $postObj->date = Carbon::parse($post->date)->timestamp;
            $postObj->text = $post->text;
            
            // Структура для likes, reposts, comments
            $postObj->likes = new \stdClass();
            $postObj->likes->count = $post->likes ?? 0;
            
            $postObj->reposts = new \stdClass();
            $postObj->reposts->count = $post->reposts ?? 0;
            
            $postObj->comments = new \stdClass();
            $postObj->comments->count = $post->comments ?? 0;

            $matchedPosts[] = $postObj;
        }

        $this->info("Найдено постов в базе данных: " . count($matchedPosts));

        return $matchedPosts;
    }

    /**
     * Подсчет статистики по найденным постам
     *
     * @param array $posts
     * @return array
     */
    private function calculateStatistics(array $posts): array
    {
        $postsCount = count($posts);
        
        if ($postsCount === 0) {
            return [
                'posts_count' => 0,
                'total_likes' => 0,
                'total_comments' => 0,
                'total_reposts' => 0,
                'avg_likes' => 0,
                'avg_comments' => 0,
                'avg_reposts' => 0,
            ];
        }

        $totalLikes = 0;
        $totalComments = 0;
        $totalReposts = 0;

        foreach ($posts as $post) {
            $totalLikes += $post->likes->count ?? 0;
            $totalComments += $post->comments->count ?? 0;
            $totalReposts += $post->reposts->count ?? 0;
        }

        return [
            'posts_count' => $postsCount,
            'total_likes' => $totalLikes,
            'total_comments' => $totalComments,
            'total_reposts' => $totalReposts,
            'avg_likes' => round($totalLikes / $postsCount, 2),
            'avg_comments' => round($totalComments / $postsCount, 2),
            'avg_reposts' => round($totalReposts / $postsCount, 2),
        ];
    }

    /**
     * Вывод таблицы в консоль
     *
     * @param array $result
     * @return void
     */
    private function displayTable(array $result): void
    {
        $stats = $result['statistics'];
        
        $this->info("Результаты поиска по слову: {$result['word']}");
        $this->info("Период: {$result['period']['from']} - {$result['period']['to']}");
        if ($result['owner_id']) {
            $this->info("Владелец: {$result['owner_id']}");
        }
        $this->newLine();

        $tableData = [
            ['Показатель', 'Значение'],
            ['Количество постов', number_format($stats['posts_count'], 0, ',', ' ')],
            ['Всего лайков', number_format($stats['total_likes'], 0, ',', ' ')],
            ['Всего комментариев', number_format($stats['total_comments'], 0, ',', ' ')],
            ['Всего репостов', number_format($stats['total_reposts'], 0, ',', ' ')],
            ['Среднее лайков', number_format($stats['avg_likes'], 2, ',', ' ')],
            ['Среднее комментариев', number_format($stats['avg_comments'], 2, ',', ' ')],
            ['Среднее репостов', number_format($stats['avg_reposts'], 2, ',', ' ')],
        ];

        $this->table(['Показатель', 'Значение'], $tableData);
    }

    /**
     * Форматирование вывода в зависимости от формата
     *
     * @param array $result
     * @param string $format
     * @return string
     */
    private function formatOutput(array $result, string $format): string
    {
        switch ($format) {
            case 'json':
                return $this->formatJson($result);
            case 'csv':
                return $this->formatCsv($result);
            case 'markdown':
                return $this->formatMarkdown($result);
            case 'table':
            default:
                return ''; // Таблица выводится отдельно через displayTable()
        }
    }

    /**
     * Форматирование в JSON
     *
     * @param array $result
     * @return string
     */
    private function formatJson(array $result): string
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        if ($json === false) {
            $error = json_last_error_msg();
            throw new \RuntimeException("Ошибка JSON encoding: {$error}");
        }
        
        return $json;
    }

    /**
     * Форматирование в CSV
     *
     * @param array $result
     * @return string
     */
    private function formatCsv(array $result): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Добавляем BOM для корректного отображения кириллицы в Excel
        fwrite($output, "\xEF\xBB\xBF");
        
        $stats = $result['statistics'];
        
        // Заголовки
        fputcsv($output, ['Показатель', 'Значение']);
        
        // Данные
        fputcsv($output, ['Слово', $result['word']]);
        fputcsv($output, ['Период от', $result['period']['from']]);
        fputcsv($output, ['Период до', $result['period']['to']]);
        if ($result['owner_id']) {
            fputcsv($output, ['Владелец', $result['owner_id']]);
        }
        fputcsv($output, ['Количество постов', $stats['posts_count']]);
        fputcsv($output, ['Всего лайков', $stats['total_likes']]);
        fputcsv($output, ['Всего комментариев', $stats['total_comments']]);
        fputcsv($output, ['Всего репостов', $stats['total_reposts']]);
        fputcsv($output, ['Среднее лайков', $stats['avg_likes']]);
        fputcsv($output, ['Среднее комментариев', $stats['avg_comments']]);
        fputcsv($output, ['Среднее репостов', $stats['avg_reposts']]);

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Форматирование в Markdown
     *
     * @param array $result
     * @return string
     */
    private function formatMarkdown(array $result): string
    {
        $output = "# Поиск постов по слову\n\n";
        $output .= "**Слово:** {$result['word']}\n\n";
        $output .= "**Период:** {$result['period']['from']} - {$result['period']['to']}\n\n";
        
        if ($result['owner_id']) {
            $output .= "**Владелец:** {$result['owner_id']}\n\n";
        }
        
        $stats = $result['statistics'];
        
        $output .= "## Статистика\n\n";
        $output .= "| Показатель | Значение |\n";
        $output .= "|------------|----------|\n";
        $output .= "| Количество постов | " . number_format($stats['posts_count'], 0, ',', ' ') . " |\n";
        $output .= "| Всего лайков | " . number_format($stats['total_likes'], 0, ',', ' ') . " |\n";
        $output .= "| Всего комментариев | " . number_format($stats['total_comments'], 0, ',', ' ') . " |\n";
        $output .= "| Всего репостов | " . number_format($stats['total_reposts'], 0, ',', ' ') . " |\n";
        $output .= "| Среднее лайков | " . number_format($stats['avg_likes'], 2, ',', ' ') . " |\n";
        $output .= "| Среднее комментариев | " . number_format($stats['avg_comments'], 2, ',', ' ') . " |\n";
        $output .= "| Среднее репостов | " . number_format($stats['avg_reposts'], 2, ',', ' ') . " |\n";
        
        $output .= "\n";
        $output .= "**Дата создания отчета:** " . date('Y-m-d H:i:s') . "\n";
        
        return $output;
    }

    /**
     * Парсинг даты в Unix timestamp
     *
     * @param string $dateString
     * @return int Unix timestamp
     * @throws \Exception
     */
    private function parseDate(string $dateString): int
    {
        $dateString = trim(strtolower($dateString));

        // Относительные даты
        $relativeDates = [
            'today' => Carbon::today(),
            'yesterday' => Carbon::yesterday(),
            'last week' => Carbon::now()->subWeek(),
            'last month' => Carbon::now()->subMonth(),
        ];

        if (isset($relativeDates[$dateString])) {
            return $relativeDates[$dateString]->timestamp;
        }

        // Попытка парсинга различных форматов
        try {
            // YYYY-MM-DD
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
                return Carbon::createFromFormat('Y-m-d', $dateString)->startOfDay()->timestamp;
            }

            // YYYY-MM-DD HH:MM:SS
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateString)) {
                return Carbon::createFromFormat('Y-m-d H:i:s', $dateString)->timestamp;
            }

            // Попытка автоматического парсинга через Carbon
            return Carbon::parse($dateString)->timestamp;
        } catch (\Exception $e) {
            throw new \Exception("Не удалось распарсить дату: {$dateString}. Поддерживаемые форматы: YYYY-MM-DD, YYYY-MM-DD HH:MM:SS, или относительные даты (today, yesterday, last week, last month)");
        }
    }

    /**
     * Форматирование даты для вывода
     *
     * @param int $timestamp
     * @return string
     */
    private function formatDate(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s');
    }
}
