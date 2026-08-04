<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PostDelete extends Command
{
    protected $signature = 'vk:post-delete
                            {id : ID записи в БД (из вывода vk:early-posts)}
                            {--dry-run : Показать, что будет удалено, без фактического удаления}';

    protected $description = 'Удаление поста из БД после републикации';

    public function handle(): int
    {
        $id = (int) $this->argument('id');

        $post = DB::table('vk_posts')->find($id);

        if (!$post) {
            $this->error("Пост с ID {$id} не найден в БД");
            return 1;
        }

        $this->line("Пост ID: {$post->id}");
        $this->line("Дата:   {$post->date}");
        $this->line("Текст:  " . mb_substr(str_replace("\n", ' ', $post->text ?? ''), 0, 120));
        $this->line("URL:    {$post->url}");
        $this->line("Лайки: {$post->likes} | Репосты: {$post->reposts} | Комментарии: {$post->comments} | Просмотры: {$post->views}");

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: удаление не выполнено');
            return 0;
        }

        if ($this->confirm('Удалить этот пост из БД?')) {
            DB::table('vk_posts')->delete($id);
            $this->info("Пост ID {$id} удалён из БД.");
            $this->line("Оригинал в VK: {$post->url}");
        } else {
            $this->line('Отмена.');
        }

        return 0;
    }
}
