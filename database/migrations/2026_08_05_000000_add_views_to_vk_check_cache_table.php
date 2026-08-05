<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vk_check_cache', function (Blueprint $table) {
            $table->integer('views')->default(0)->after('reposts');
        });
    }

    public function down(): void
    {
        Schema::table('vk_check_cache', function (Blueprint $table) {
            $table->dropColumn('views');
        });
    }
};
