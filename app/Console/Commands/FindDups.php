<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkUrlBuilder;

class FindDups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:dups {--owner=} {--text=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Поиск дублей'; 


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
     * 
     */
    public function handle()
    {
        $wallService = new VkWallService();
        $wallService->setOwner($this->option('owner'));
        
        $offset = 0;
        while(true) {
            $posts = $wallService->getPosts(100, $offset);
            if (!count($posts)) {
                break;
            }
            foreach ($posts as $post) {
                if (!preg_match("/{$this->option('text')}/iu", $post->text)) {
                    continue;
                }
                $this->info(json_encode([
                    'url' => VkUrlBuilder::wallPost($this->option('owner'), $post->id), 
                    'likes' => $post->likes->count, 
                    'reposts' => $post->reposts->count
                ]));
            }                    
            $offset += 100;
            
        }
    }
}
