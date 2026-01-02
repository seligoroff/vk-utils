<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\VkApi\VkWallService;

class FindPostComments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'post:comments {--owner=} {--post=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        
        $comments = $wallService->getComments($this->option('post'));
        foreach ($comments as $comment) {
            dump($wallService->getComment($comment->id));
        }
    }
}
