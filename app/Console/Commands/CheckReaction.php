<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkGroupService;
use App\Models\Resource;


class CheckReaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:check 
                            {--cached : Использовать кеш из предыдущего запуска}
                            {--delay=0.3 : Задержка между запросами в секундах}
                            {--format=table : Формат вывода: table, json, csv, markdown}
                            {--output= : Путь к файлу для сохранения результатов (опциональный)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check last posts in group list';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            $list = Resource::getList();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }        
        $wallService = new VkWallService();
        $data = [];
        
        // Проверяем, нужно ли использовать кэш из БД
        $useCache = $this->option('cached') && $this->hasCacheInDatabase();
        
        if (!$useCache) {
            // Очищаем старый кэш перед созданием нового
            $this->clearCache();
            
            $progressbar = $this->output->createProgressBar(count($list));
            $progressbar->start();
            
            foreach ($list as $name) {
                try {
                    $meta  = VkGroupService::resolveName($name);
                    $wallService->setOwner("-{$meta->object_id}");
                    $group = VkGroupService::getById($meta->object_id);
                    $posts = $wallService->getPosts();
                } catch (\Throwable $e) {
                    $this->alert($e->getMessage());
                    continue;  
                }
                if (empty($posts)) {
                    continue;
                }
                foreach ($posts as $post) {
                    if (!empty($post->text)) {
                        break;
                    }
                }
                
                $postText = Str::limit($post->text, 40);
                $groupName = $group->name;
                $groupId = $meta->object_id;
                $likes = $post->likes->count ?? 0;
                $reposts = $post->reposts->count ?? 0;
                
                // Сохраняем в БД
                $this->saveToCache($groupName, $groupId, $postText, $likes, $reposts);
                
                // Добавляем в массив для вывода
                $data[] = [
                    $postText,
                    $groupName,
                    $groupId,
                    $likes,
                    $reposts
                ];             
                $progressbar->advance();
                if ($this->option('delay')) {
                    usleep(1000000 * $this->option('delay'));    
                }
            }
            $progressbar->finish();
        } else {
            // Загружаем данные из БД
            $data = $this->loadFromCache();
        }
        
        // Преобразуем данные в структурированный формат
        $structuredData = $this->prepareStructuredData($data);
        
        // Определяем формат вывода
        $format = $this->option('format') ?? 'table';
        if (!in_array($format, ['table', 'json', 'csv', 'markdown'])) {
            $this->error('Неверный формат. Допустимые значения: table, json, csv, markdown');
            return 1;
        }
        
        // Если указан путь для сохранения
        if ($this->option('output')) {
            $outputPath = $this->option('output');
            
            // Определяем формат по расширению файла, если не указан явно
            $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
            if (in_array($extension, ['json', 'csv'])) {
                $format = $extension;
            } elseif (in_array($extension, ['md', 'markdown'])) {
                // Автоматически используем Markdown формат для .md и .markdown
                $format = 'markdown';
            } elseif ($format === 'table' && !in_array($extension, ['json', 'csv', 'md', 'markdown'])) {
                // Если формат table и расширение не указано, сохраняем в CSV
                $format = 'csv';
            }
            
            // Форматируем данные
            $fileOutput = $this->formatOutput($structuredData, $format);
            
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
            
            // Для формата table и markdown всегда выводим таблицу в консоль, даже если сохраняем в файл
            if ($format === 'table' || $format === 'markdown' || $this->option('format') === 'table') {
                $this->displayTable($structuredData);
            }
        } else {
            // Вывод в консоль
            $output = $this->formatOutput($structuredData, $format);
            
            if ($format === 'table') {
                $this->displayTable($structuredData);
            } else {
                $this->line($output);
            }
        }
        
        return 0;
    }

    /**
     * Проверить, есть ли кэш в БД
     *
     * @return bool
     */
    private function hasCacheInDatabase(): bool
    {
        if (!Schema::hasTable('vk_check_cache')) {
            return false;
        }
        
        return DB::table('vk_check_cache')->exists();
    }

    /**
     * Очистить кэш в БД
     *
     * @return void
     */
    private function clearCache(): void
    {
        if (Schema::hasTable('vk_check_cache')) {
            DB::table('vk_check_cache')->truncate();
        }
    }

    /**
     * Сохранить данные в кэш БД
     *
     * @param string $groupName
     * @param int $groupId
     * @param string $postText
     * @param int $likes
     * @param int $reposts
     * @return void
     */
    private function saveToCache(string $groupName, int $groupId, string $postText, int $likes, int $reposts): void
    {
        if (!Schema::hasTable('vk_check_cache')) {
            $this->warn('Таблица vk_check_cache не существует. Запустите миграцию: php artisan migrate');
            return;
        }

        DB::table('vk_check_cache')->insert([
            'group_name' => $groupName,
            'group_id' => $groupId,
            'post_text' => $postText,
            'likes' => $likes,
            'reposts' => $reposts,
            'cached_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Загрузить данные из кэша БД
     *
     * @return array
     */
    private function loadFromCache(): array
    {
        if (!Schema::hasTable('vk_check_cache')) {
            return [];
        }

        $cacheData = DB::table('vk_check_cache')
            ->orderBy('cached_at', 'desc')
            ->get();

        $data = [];
        foreach ($cacheData as $row) {
            $data[] = [
                $row->post_text,
                $row->group_name,
                $row->group_id,
                $row->likes,
                $row->reposts
            ];
        }

        return $data;
    }

    /**
     * Преобразовать данные в структурированный формат
     *
     * @param array $data
     * @return array
     */
    private function prepareStructuredData(array $data): array
    {
        $structured = [];
        foreach ($data as $row) {
            $structured[] = [
                'post_text' => $row[0] ?? '',
                'group_name' => $row[1] ?? '',
                'group_id' => $row[2] ?? 0,
                'likes' => $row[3] ?? 0,
                'reposts' => $row[4] ?? 0,
            ];
        }
        return $structured;
    }

    /**
     * Форматирование вывода в зависимости от формата
     *
     * @param array $data
     * @param string $format
     * @return string
     */
    private function formatOutput(array $data, string $format): string
    {
        switch ($format) {
            case 'json':
                return $this->formatJson($data);
            case 'csv':
                return $this->formatCsv($data);
            case 'markdown':
                return $this->formatMarkdown($data);
            case 'table':
            default:
                return ''; // Таблица выводится отдельно через displayTable()
        }
    }

    /**
     * Вывод таблицы в консоль
     *
     * @param array $data
     * @return void
     */
    private function displayTable(array $data): void
    {
        $tableData = [];
        foreach ($data as $row) {
            $tableData[] = [
                Str::limit($row['post_text'], 40) ?: '(без текста)',
                $row['group_name'],
                $row['group_id'],
                $row['likes'],
                $row['reposts'],
            ];
        }
        
        $this->table(['Post', 'Group name', 'Group ID', 'Likes', 'Reposts'], $tableData);
    }

    /**
     * Форматирование в JSON
     *
     * @param array $data
     * @return string
     */
    private function formatJson(array $data): string
    {
        $result = [];
        foreach ($data as $row) {
            $result[] = [
                'post_text' => $row['post_text'],
                'group_name' => $row['group_name'],
                'group_id' => $row['group_id'],
                'likes' => $row['likes'],
                'reposts' => $row['reposts'],
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
     * @param array $data
     * @return string
     */
    private function formatCsv(array $data): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Добавляем BOM для корректного отображения кириллицы в Excel
        fwrite($output, "\xEF\xBB\xBF");
        
        // Заголовки
        fputcsv($output, ['post_text', 'group_name', 'group_id', 'likes', 'reposts']);

        // Данные
        foreach ($data as $row) {
            fputcsv($output, [
                $row['post_text'],
                $row['group_name'],
                $row['group_id'],
                $row['likes'],
                $row['reposts'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Форматирование в Markdown
     *
     * @param array $data
     * @return string
     */
    private function formatMarkdown(array $data): string
    {
        $output = "# Проверка последних постов в группах VK\n\n";
        $output .= "**Дата проверки:** " . date('Y-m-d H:i:s') . "\n\n";
        
        if (empty($data)) {
            $output .= "Нет данных для отображения.\n";
            return $output;
        }
        
        $output .= "## Результаты\n\n";
        $output .= "| Post | Group name | Group ID | Likes | Reposts |\n";
        $output .= "|------|------------|----------|-------|----------|\n";
        
        foreach ($data as $row) {
            $postText = $row['post_text'] ?: '(без текста)';
            // Убираем переносы строк и лишние пробелы для корректного отображения в таблице
            $postText = preg_replace('/\s+/', ' ', $postText);
            $postText = trim($postText);
            $postText = Str::limit($postText, 50);
            // Экранируем символы для Markdown
            $postText = str_replace('|', '\\|', $postText);
            
            $groupName = str_replace('|', '\\|', $row['group_name']);
            
            $output .= sprintf(
                "| %s | %s | %d | %d | %d |\n",
                $postText,
                $groupName,
                $row['group_id'],
                $row['likes'],
                $row['reposts']
            );
        }
        
        $output .= "\n";
        $output .= "**Всего групп:** " . count($data) . "\n";
        
        return $output;
    }
}
