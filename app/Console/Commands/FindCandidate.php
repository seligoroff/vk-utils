<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkUrlBuilder;


class FindCandidate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:candidate {--owner=} {--noreposts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Поиск кандата для републикации'; 


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
        
        $wallService = new VkWallService();
        $wallService->setOwner($this->option('owner'));
        $offset = 0;
        $all = [];
        while(true) {           
            $posts = $wallService->getPosts(100, $offset);
            if (!count($posts)) {
                break;
            }
            
            foreach ($posts as $post) {
                array_push($all, $post);
            }
            sleep(1);
            $offset += 100;
        }
        
        $all = array_reverse($all);
        
        foreach ($all as $post) {
            if (empty($post->text) || ($this->option('noreposts') &&  $post->reposts->count > 0)) {
                continue;
            }
            
            $this->info($post->text);
            $this->info(VkUrlBuilder::wallPost($this->option('owner'), $post->id));
            $this->info("LIKES {$post->likes->count} REPOSTS {$post->reposts->count}");
            $this->info(str_repeat('=', 100));
            sleep(5);
        }
        
    }
}
