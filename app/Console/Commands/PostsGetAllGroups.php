<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkGroupService;
use App\Services\VkApi\VkUrlBuilder;
use App\Models\Resource;
use App\Support\VkPostPeriod;
use App\Support\VkWallPost;

class PostsGetAllGroups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:posts-get-all
                            {--from= : Дата начала периода (обязательный)}
                            {--to= : Дата окончания периода (опциональный, по умолчанию текущая дата)}
                            {--delay=0.3 : Задержка между запросами к группам в секундах (по умолчанию 0.3)}
                            {--no-clear : Не удалять посты в БД перед загрузкой (только добавление без дубликатов)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получение постов для всех групп из vk-groups.csv с сохранением в БД (аналог vk:posts-get --db --clear для каждой группы)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Валидация обязательных параметров
        if (!$this->option('from')) {
            $this->error('Параметр --from обязателен');
            return 1;
        }

        try {
            $period = VkPostPeriod::fromCommandOptions(
                $this->option('from'),
                $this->option('to')
            );
        } catch (\Exception $e) {
            $this->error('Ошибка парсинга даты: ' . $e->getMessage());
            return 1;
        }

        // Загрузка списка групп
        try {
            $groupList = Resource::getList();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        if (empty($groupList)) {
            $this->warn('Список групп пуст. Убедитесь, что файл resources/vk-groups.csv содержит данные.');
            return 1;
        }

        // Проверяем, существует ли таблица
        if (!Schema::hasTable('vk_posts')) {
            $this->error('Таблица vk_posts не существует. Запустите миграцию: php artisan migrate');
            return 1;
        }

        $resolvedGroups = $this->resolveGroupsFromList($groupList);
        $allowedOwnerIds = array_values(array_unique(array_column($resolvedGroups, 'owner_id')));

        if (empty($resolvedGroups)) {
            $this->error('Не удалось резолвить ни одной записи из vk-groups.csv');
            return 1;
        }

        $resolveErrors = count($groupList) - count($resolvedGroups);

        $this->info('Записей в vk-groups.csv: ' . count($groupList) . ', резолвлено: ' . count($resolvedGroups));
        $this->info("Период: с {$period->fromLabel()} по {$period->toInclusiveLabel()}");
        $this->newLine();
        $this->line('Owner_id из конфигурации (очистка БД только для них, только за указанный период):');
        foreach ($resolvedGroups as $group) {
            $this->line("  {$group['owner_id']} ← {$group['screen_name']} ({$group['type']})");
        }
        $this->line('Посты других owner_id в vk_posts не удаляются.');
        if ($this->option('no-clear')) {
            $this->warn('Режим --no-clear: существующие посты в БД не удаляются перед загрузкой.');
        }
        $this->newLine();

        $wallService = new VkWallService();
        $totalProcessed = 0;
        $totalSaved = 0;
        $totalCleared = 0;
        $errors = $resolveErrors;
        $clearedByOwner = [];
        $delay = (float) $this->option('delay');
        $skipClear = (bool) $this->option('no-clear');

        $progressBar = $this->output->createProgressBar(count($resolvedGroups));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->start();

        foreach ($resolvedGroups as $group) {
            $groupName = $group['screen_name'];
            $ownerId = $group['owner_id'];
            $progressBar->setMessage("Обработка: {$groupName}");

            try {
                // Получаем посты за период
                $wallService->setOwner($ownerId);
                $allPosts = $this->getPostsForPeriod($wallService, $period);

                // Очистка БД после успешной загрузки (если не --no-clear)
                if (!$skipClear) {
                    $deleted = $this->clearDatabaseForOwner(
                        $ownerId,
                        $allowedOwnerIds,
                        $period
                    );
                    if ($deleted > 0) {
                        $clearedByOwner[$ownerId] = $deleted;
                        $totalCleared += $deleted;
                    }
                }

                if (empty($allPosts)) {
                    $progressBar->setMessage("✓ Нет постов: {$groupName}");
                    $progressBar->advance();
                    $totalProcessed++;

                    if ($delay > 0) {
                        usleep(1000000 * $delay);
                    }
                    continue;
                }

                // Сохраняем посты в БД
                $saved = $this->savePostsToDatabase($allPosts, $ownerId);
                $totalSaved += $saved;
                $totalProcessed++;

                $progressBar->setMessage("✓ Сохранено {$saved} постов: {$groupName}");

            } catch (\Throwable $e) {
                $errors++;
                $progressBar->setMessage("✗ Ошибка: {$groupName} - " . $e->getMessage());
                if ($this->option('verbose')) {
                    $this->newLine();
                    $this->error("Ошибка при обработке группы {$groupName}: " . $e->getMessage());
                }
            }

            $progressBar->advance();

            // Задержка между запросами
            if ($delay > 0) {
                usleep(1000000 * $delay);
            }
        }

        $progressBar->setMessage('');
        $progressBar->finish();
        $this->newLine(2);

        // Выводим итоговую статистику
        $this->info('=== Результаты ===');
        $this->line("Обработано групп: {$totalProcessed}");
        $this->line("Всего сохранено постов: {$totalSaved}");
        if (!$skipClear) {
            $this->line("Всего удалено из БД за период: {$totalCleared}");
            if (!empty($clearedByOwner)) {
                $this->line('Удалено по owner_id:');
                foreach ($clearedByOwner as $oid => $cnt) {
                    $this->line("  {$oid}: {$cnt}");
                }
            }
        }
        if ($errors > 0) {
            $this->warn("Ошибок: {$errors}");
        }

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Резолв screen name из vk-groups.csv в owner_id для стены.
     *
     * @param array<int, string> $groupList
     * @return array<int, array{screen_name: string, owner_id: string, type: string}>
     */
    private function resolveGroupsFromList(array $groupList): array
    {
        $resolved = [];

        foreach ($groupList as $screenName) {
            $meta = VkGroupService::resolveName($screenName);
            usleep(350000); // 0.35s между резолвами — защита от rate limit

            if (!$meta || !isset($meta->object_id)) {
                if ($this->option('verbose')) {
                    $this->warn("Не удалось резолвить: {$screenName}");
                }
                continue;
            }

            $ownerId = VkGroupService::wallOwnerIdFromResolved($meta);
            if ($ownerId === null) {
                continue;
            }

            $resolved[] = [
                'screen_name' => $screenName,
                'owner_id' => $ownerId,
                'type' => $meta->type ?? 'unknown',
            ];
        }

        return $resolved;
    }

    /**
     * Получить все посты за период
     *
     * @param VkWallService $wallService
     * @param VkPostPeriod $period
     * @return array
     */
    private function getPostsForPeriod(VkWallService $wallService, VkPostPeriod $period): array
    {
        $allPosts = [];
        $offset = 0;
        $count = 100;

        while (true) {
            $posts = $wallService->getPosts($count, $offset);

            if (empty($posts)) {
                break;
            }

            // Фильтруем посты по дате [from, to)
            foreach ($posts as $post) {
                $postDate = VkWallPost::timestamp($post);
                if ($postDate === null) {
                    continue;
                }

                if ($period->containsTimestamp($postDate)) {
                    $allPosts[] = $post;
                } elseif (VkWallPost::shouldStopPagination($post, $period->fromInclusive)) {
                    return $allPosts;
                }
            }

            // Если получили меньше постов чем запрашивали, значит это последняя страница
            if (count($posts) < $count) {
                break;
            }

            $offset += $count;

            // Небольшая задержка между запросами к API
            usleep(300000); // 0.3 секунды
        }

        return $allPosts;
    }

    /**
     * Очистить в БД посты владельца за указанный период.
     * Удаление только если owner_id входит в allowlist из vk-groups.csv.
     *
     * @param string $ownerId
     * @param array<int, string> $allowedOwnerIds
     * @return int Количество удалённых строк
     */
    private function clearDatabaseForOwner(
        string $ownerId,
        array $allowedOwnerIds,
        VkPostPeriod $period
    ): int {
        if (!in_array($ownerId, $allowedOwnerIds, true)) {
            $this->error("Отказ очистки: owner_id {$ownerId} отсутствует в списке из vk-groups.csv");

            return 0;
        }

        try {
            return DB::table('vk_posts')
                ->where('owner_id', $ownerId)
                ->where('timestamp', '>=', $period->fromInclusive)
                ->where('timestamp', '<', $period->toExclusive)
                ->delete();
        } catch (\Exception $e) {
            if ($this->option('verbose')) {
                $this->warn("Ошибка при очистке БД для {$ownerId}: " . $e->getMessage());
            }

            return 0;
        }
    }

    /**
     * Сохранить посты в базу данных
     *
     * @param array $posts
     * @param string $ownerId
     * @return int Количество сохраненных постов
     */
    private function savePostsToDatabase(array $posts, string $ownerId): int
    {
        if (empty($posts)) {
            return 0;
        }

        $saved = 0;

        try {
            foreach ($posts as $post) {
                try {
                    $postData = [
                        'post_id' => $post->id ?? null,
                        'owner_id' => $ownerId,
                        'timestamp' => $post->date ?? 0,
                        'date' => Carbon::createFromTimestamp($post->date ?? 0)->toDateTimeString(),
                        'text' => $post->text ?? null,
                        'likes' => $post->likes->count ?? 0,
                        'reposts' => $post->reposts->count ?? 0,
                        'comments' => $post->comments->count ?? 0,
                        'views' => $post->views->count ?? 0,
                        'url' => VkUrlBuilder::wallPost($ownerId, $post->id),
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];

                    // Проверяем на дубликаты перед вставкой
                    $exists = DB::table('vk_posts')
                        ->where('owner_id', $ownerId)
                        ->where('post_id', $post->id ?? null)
                        ->exists();

                    if (!$exists) {
                        DB::table('vk_posts')->insert($postData);
                        $saved++;
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибки отдельных постов
                    if ($this->option('verbose')) {
                        $this->warn("Ошибка при сохранении поста ID {$post->id}: " . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error('Ошибка при сохранении в базу данных: ' . $e->getMessage());
        }

        return $saved;
    }

}

