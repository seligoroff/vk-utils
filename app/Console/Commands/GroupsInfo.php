<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\VkApi\VkGroupService;
use App\Models\Resource;

class GroupsInfo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:groups:info';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получение информации о группах из списка resources.csv';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $list = Resource::getList();
        
        if (empty($list)) {
            $this->error('Список групп пуст. Убедитесь, что файл storage/app/resources.csv существует и содержит данные.');
            return 1;
        }
        
        $data = [];
        $progressbar = $this->output->createProgressBar(count($list));
        $progressbar->start();
        
        foreach ($list as $name) {
            try {
                $meta = VkGroupService::resolveName($name);
                
                if (!$meta || !isset($meta->object_id)) {
                    $this->newLine();
                    $this->warn("Не удалось резолвить группу: {$name}");
                    $progressbar->advance();
                    continue;
                }
                
                $group = VkGroupService::getById($meta->object_id);
                
                if (!$group) {
                    $this->newLine();
                    $this->warn("Не удалось получить информацию о группе: {$name} (ID: {$meta->object_id})");
                    $progressbar->advance();
                    continue;
                }
                
                $data[] = [
                    $group->name ?? 'N/A',
                    $meta->object_id,
                    $meta->type ?? 'N/A'
                ];
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Ошибка при обработке группы {$name}: {$e->getMessage()}");
            }
            
            usleep(500000); // 0.5 секунды задержка между запросами
            $progressbar->advance();
        }
        
        $progressbar->finish();
        $this->newLine(2);
        
        if (empty($data)) {
            $this->warn('Не удалось получить информацию ни об одной группе.');
            return 1;
        }
        
        $this->table(['Name', 'ID', 'Type'], $data);
        
        return 0;
    }
}

