<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
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
    protected $signature = 'vk:check {--cached} {--delay=0.3}';

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
        $list = Resource::getList();        
        $wallService = new VkWallService();
        $data = [];
        if (!$this->option('cached') || !Storage::disk('local')->exists('cache-resources.json')) {
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
                $data[] = [
                    Str::limit($post->text, 40),
                    $group->name,
                    $meta->object_id,
                    $post->likes->count,
                    $post->reposts->count
                ];             
                $progressbar->advance();
                if ($this->option('delay')) {
                    usleep(1000000 * $this->option('delay'));    
                }
            }
            $progressbar->finish();
            Storage::disk('local')->put('cache-resources.json', json_encode($data));            
        } else {
            $data = json_decode(Storage::disk('local')->get('cache-resources.json'));
        }
        
        $this->table(['Post', 'Group name', 'Group ID', 'Likes', 'Reposts'], $data);
    }
}
