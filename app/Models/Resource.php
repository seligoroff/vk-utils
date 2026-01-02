<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;


class Resource extends Model
{
    use HasFactory;
    
    
    public static function getList()
    {
        $stream  = Storage::disk('local')->readStream('resources.csv');
        $resources = [];
        while (($row = fgetcsv($stream)) !== false) {
            $resources[] = trim(parse_url($row[0], PHP_URL_PATH), '/ ');            
        }
        return $resources;
    }
}
