<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkUrlBuilder;

class FindAuthorComments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'comments:find {--owner=} {--author=}';

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
        
        $offset = 0;
        
        while(true) {           
            $posts = $wallService->getPosts(100, $offset);
            if (!count($posts)) {
                break;
            }
            
            foreach ($posts as $post) {                
                $this->_parseComments($wallService, $post->id);                
            }
                                    
            sleep(1);
            $offset += 100;
        }
    }
    
    
    private function _parseComments($wallService, $postId)
    {
        $comments_offset = 0;
        $author = $this->option('author');
        
        while (true) {
            
            $comments = $wallService->getComments($postId, $comments_offset);
            if (empty($comments)) {
                break;
            }
            
            foreach ($comments as $comment) {
                if ($comment->from_id != $author) {
                    continue;
                }
                dump($comment->text);
                dump(VkUrlBuilder::wallComment($this->option('owner'), $postId, $comment->id));
            }
            $comments_offset += 100;
        }
    }
    
    
}
