<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\VkApi\VkSdkAdapter;

/**
 * TODO: Метод wall.post требует community access token с правом manage.
 * Текущий user token (VK_TOKEN) имеет только wall scope (чтение),
 * публикация от имени групп недоступна.
 * Команда написана, протестирована в dry-run, но НЕ ИСПОЛЬЗУЕТСЯ
 * до получения community token с соответствующими правами.
 */
class PostRepost extends Command
{
    protected $signature = 'vk:post-repost
                            {id : ID записи в БД (из вывода vk:early-posts)}
                            {--owner= : ID группы для публикации (отрицательное число, например -114212805). Если не указан — та же группа, что и у поста-источника}
                            {--dry-run : Только показать, что будет сделано, без реальной публикации}';

    protected $description = 'Повторная публикация поста от имени группы и удаление из БД';

    public function handle(): int
    {
        $id = (int) $this->argument('id');

        $post = DB::table('vk_posts')->find($id);
        if (!$post) {
            $this->error("Пост с ID {$id} не найден в БД");
            return 1;
        }

        $ownerId = $this->option('owner') ?: $post->owner_id;

        $this->line("Пост:     ID {$post->id}, {$post->date}");
        $this->line("Текст:    " . mb_substr(str_replace("\n", ' ', $post->text ?? ''), 0, 120));
        $this->line("Источник: {$post->url}");
        $this->line("Публикация в: {$ownerId}");

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: публикация и удаление не выполнены');
            return 0;
        }

        if (!$this->confirm('Опубликовать этот текст от имени группы?')) {
            $this->line('Отмена.');
            return 0;
        }

        $adapter = new VkSdkAdapter();

        try {
            $result = $adapter->wall()->post($adapter->getToken(), [
                'owner_id' => (int) $ownerId,
                'from_group' => 1,
                'message' => $post->text,
            ]);

            $this->info('Опубликовано успешно.');

            if (is_array($result) && isset($result['post_id'])) {
                $gid = ltrim((string) $ownerId, '-');
                $this->info("Новый пост: https://vk.com/wall{$ownerId}_{$result['post_id']}");
            }

            DB::table('vk_posts')->delete($id);
            $this->info("Пост ID {$id} удалён из БД.");
            $this->line("Оригинал в VK для ручного удаления: {$post->url}");

        } catch (\VK\Exceptions\VKApiException $e) {
            $this->error('Ошибка VK API: ' . $e->getMessage());
            $this->warn('Пост НЕ удалён из БД — можно повторить.');
            return 1;
        } catch (\VK\Exceptions\VKClientException $e) {
            $this->error('Сетевая ошибка: ' . $e->getMessage());
            $this->warn('Пост НЕ удалён из БД — можно повторить.');
            return 1;
        }

        return 0;
    }
}
