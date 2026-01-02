<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkUrlBuilder;

class GetPosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:posts:get 
                            {--owner= : ID владельца стены (обязательный, отрицательное число для групп)}
                            {--from= : Дата начала периода (обязательный)}
                            {--to= : Дата окончания периода (опциональный, по умолчанию текущая дата)}
                            {--format=table : Формат вывода: table, json, csv}
                            {--output= : Путь к файлу для сохранения результатов (опциональный)}
                            {--db : Сохранить результаты в SQLite базу данных (вместо файла)}
                            {--with-text-only : Показывать только посты с текстом}
                            {--min-likes= : Минимальное количество лайков}
                            {--min-reposts= : Минимальное количество репостов}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получение постов группы/пользователя за указанный период';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Валидация обязательных параметров
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
        if (!in_array($format, ['table', 'json', 'csv'])) {
            $this->error('Неверный формат. Допустимые значения: table, json, csv');
            return 1;
        }

        // Получение постов
        $this->info("Получение постов с {$this->formatDate($fromTimestamp)} по {$this->formatDate($toTimestamp)}...");
        
        $wallService = new VkWallService();
        $wallService->setOwner($this->option('owner'));

        $filteredPosts = [];
        $offset = 0;
        $totalProcessed = 0;
        $progressBar = null;

        try {
            while (true) {
                $posts = $wallService->getPosts(100, $offset);
                
                if (empty($posts) || !is_array($posts)) {
                    break;
                }

                // Инициализация progress bar при первом запросе
                if ($progressBar === null && count($posts) > 0) {
                    // Оцениваем общее количество постов (приблизительно)
                    $progressBar = $this->output->createProgressBar();
                    $progressBar->setFormat(' %current% постов обработано');
                    $progressBar->start();
                }

                $shouldBreak = false;
                foreach ($posts as $post) {
                    $totalProcessed++;
                    
                    // Фильтрация по дате
                    if (!isset($post->date)) {
                        continue;
                    }
                    
                    // Если пост старше начала периода, прекращаем обработку
                    // (посты обычно идут от новых к старым)
                    if ($post->date < $fromTimestamp) {
                        $shouldBreak = true;
                        break;
                    }
                    
                    if ($post->date > $toTimestamp) {
                        continue;
                    }

                    // Фильтрация по тексту
                    if ($this->option('with-text-only') && empty($post->text)) {
                        continue;
                    }

                    // Фильтрация по лайкам
                    if ($this->option('min-likes') && (!isset($post->likes->count) || $post->likes->count < (int)$this->option('min-likes'))) {
                        continue;
                    }

                    // Фильтрация по репостам
                    if ($this->option('min-reposts') && (!isset($post->reposts->count) || $post->reposts->count < (int)$this->option('min-reposts'))) {
                        continue;
                    }

                    $filteredPosts[] = $post;
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

        if (empty($filteredPosts)) {
            $this->warn('Посты за указанный период не найдены');
            return 0;
        }

        // Сортировка по дате (от новых к старым)
        usort($filteredPosts, function($a, $b) {
            return ($b->date ?? 0) - ($a->date ?? 0);
        });

        // Сохранение в SQLite базу данных
        if ($this->option('db')) {
            $this->saveToDatabase($filteredPosts);
        } elseif ($this->option('output')) {
            // Определяем формат для сохранения в файл
            $saveFormat = $format;
            $outputPath = $this->option('output');
            // Определяем формат по расширению файла
            $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
            if (in_array($extension, ['json', 'csv'])) {
                $saveFormat = $extension;
            } elseif ($format === 'table') {
                // Если формат table и расширение не указано, сохраняем в CSV
                $saveFormat = 'csv';
            }
            
            // Форматируем данные для сохранения в нужном формате
            $fileOutput = $this->formatOutput($filteredPosts, $saveFormat);
            
            // Проверяем, что данные не пустые
            if (empty($fileOutput) && !empty($filteredPosts)) {
                $this->error('Ошибка: не удалось отформатировать данные для сохранения');
                $this->error("Формат сохранения: {$saveFormat}, Количество постов: " . count($filteredPosts));
                return 1;
            }
            
            if (empty($fileOutput)) {
                $this->warn('Предупреждение: данные для сохранения пусты');
            }
            
            // Если путь относительный и не начинается с storage/, считаем его абсолютным от корня проекта
            $finalPath = $outputPath;
            if (strpos($finalPath, '/') !== 0 && strpos($finalPath, 'storage/') !== 0) {
                $finalPath = base_path($finalPath);
            } elseif (strpos($finalPath, '/') !== 0) {
                // Относительный путь от storage/app/
                $finalPath = storage_path('app/' . $finalPath);
            }
            
            // Создаем директории, если их нет
            $directory = dirname($finalPath);
            if (!is_dir($directory)) {
                if (!mkdir($directory, 0755, true)) {
                    $this->error("Не удалось создать директорию: {$directory}");
                    return 1;
                }
            }
            
            $bytesWritten = file_put_contents($finalPath, $fileOutput);
            if ($bytesWritten === false) {
                $this->error("Ошибка при сохранении файла: {$finalPath}");
                return 1;
            }
            
            $this->info("Результаты сохранены в файл: {$finalPath} ({$bytesWritten} байт)");
        } else {
            // Вывод в консоль (только если не сохраняем в БД)
            $output = $this->formatOutput($filteredPosts, $format);
            
            // Для формата table всегда выводим таблицу в консоль
            if ($format === 'table') {
                $this->line($output);
            } else {
                // Вывод в stdout для json и csv
                $this->line($output);
            }
        }
        

        // Статистика
        $this->displayStatistics($filteredPosts);

        return 0;
    }

    /**
     * Парсинг даты из различных форматов
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

    /**
     * Форматирование вывода в зависимости от формата
     *
     * @param array $posts
     * @param string $format
     * @return string
     */
    private function formatOutput(array $posts, string $format): string
    {
        switch ($format) {
            case 'json':
                return $this->formatJson($posts);
            case 'csv':
                return $this->formatCsv($posts);
            case 'table':
            default:
                return $this->formatTable($posts);
        }
    }

    /**
     * Форматирование в таблицу
     *
     * @param array $posts
     * @return string
     */
    private function formatTable(array $posts): string
    {
        $data = [];
        foreach ($posts as $post) {
            // Очистка текста: убираем переносы строк и лишние пробелы
            $text = $post->text ?? '';
            $text = preg_replace('/\s+/', ' ', $text); // Заменяем все пробельные символы на один пробел
            $text = trim($text);
            $text = \Illuminate\Support\Str::limit($text, 40);
            
            $data[] = [
                $this->formatDate($post->date ?? 0),
                $text ?: '(без текста)',
                $post->likes->count ?? 0,
                $post->reposts->count ?? 0,
                $post->comments->count ?? 0,
                VkUrlBuilder::wallPost($this->option('owner'), $post->id),
            ];
        }

        $this->table(
            ['Date', 'Text', 'Likes', 'Reposts', 'Comments', 'URL'],
            $data
        );

        return ''; // Таблица уже выведена через $this->table()
    }

    /**
     * Форматирование в JSON
     *
     * @param array $posts
     * @return string
     */
    private function formatJson(array $posts): string
    {
        $result = [];
        foreach ($posts as $post) {
            $result[] = [
                'id' => $post->id ?? null,
                'date' => $this->formatDate($post->date ?? 0),
                'timestamp' => $post->date ?? 0,
                'text' => $post->text ?? '',
                'likes' => $post->likes->count ?? 0,
                'reposts' => $post->reposts->count ?? 0,
                'comments' => $post->comments->count ?? 0,
                'url' => VkUrlBuilder::wallPost($this->option('owner'), $post->id),
            ];
        }

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
     * @param array $posts
     * @return string
     */
    private function formatCsv(array $posts): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Заголовки
        fputcsv($output, ['date', 'text', 'likes', 'reposts', 'comments', 'url']);

        // Данные
        foreach ($posts as $post) {
            fputcsv($output, [
                $this->formatDate($post->date ?? 0),
                $post->text ?? '',
                $post->likes->count ?? 0,
                $post->reposts->count ?? 0,
                $post->comments->count ?? 0,
                VkUrlBuilder::wallPost($this->option('owner'), $post->id),
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Сохранение постов в SQLite базу данных
     *
     * @param array $posts
     * @return void
     */
    private function saveToDatabase(array $posts): void
    {
        if (empty($posts)) {
            $this->warn('Нет данных для сохранения в базу данных');
            return;
        }

        // Проверяем, существует ли таблица
        if (!Schema::hasTable('vk_posts')) {
            $this->error('Таблица vk_posts не существует. Запустите миграцию: php artisan migrate');
            return;
        }

        $this->info("Сохранение " . count($posts) . " постов в базу данных...");
        
        $saved = 0;
        $skipped = 0;
        $progressBar = $this->output->createProgressBar(count($posts));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $progressBar->start();

        try {
            foreach ($posts as $post) {
                try {
                    $exists = DB::table('vk_posts')
                        ->where('owner_id', (string)$this->option('owner'))
                        ->where('post_id', $post->id ?? null)
                        ->exists();
                    
                    if (!$exists) {
                        DB::table('vk_posts')->insert([
                            'post_id' => $post->id ?? null,
                            'owner_id' => (string)$this->option('owner'),
                            'timestamp' => $post->date ?? 0,
                            'date' => Carbon::createFromTimestamp($post->date ?? 0)->toDateTimeString(),
                            'text' => $post->text ?? null,
                            'likes' => $post->likes->count ?? 0,
                            'reposts' => $post->reposts->count ?? 0,
                            'comments' => $post->comments->count ?? 0,
                            'url' => VkUrlBuilder::wallPost($this->option('owner'), $post->id),
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                        $saved++;
                    } else {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $skipped++;
                }
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $this->newLine();
            $this->info("Сохранено постов: {$saved}, пропущено (дубли): {$skipped}");
            
        } catch (\Exception $e) {
            $progressBar->finish();
            $this->newLine();
            $this->error('Ошибка при сохранении в базу данных: ' . $e->getMessage());
        }
    }

    /**
     * Вывод статистики
     *
     * @param array $posts
     * @return void
     */
    private function displayStatistics(array $posts): void
    {
        if (empty($posts)) {
            return;
        }

        $totalLikes = 0;
        $totalReposts = 0;
        $totalComments = 0;

        foreach ($posts as $post) {
            $totalLikes += $post->likes->count ?? 0;
            $totalReposts += $post->reposts->count ?? 0;
            $totalComments += $post->comments->count ?? 0;
        }

        $count = count($posts);
        
        $this->newLine();
        $this->info('Статистика:');
        $this->line("- Всего постов: {$count}");
        $this->line("- Среднее лайков: " . round($totalLikes / $count, 2));
        $this->line("- Среднее репостов: " . round($totalReposts / $count, 2));
        $this->line("- Среднее комментариев: " . round($totalComments / $count, 2));
    }
}

