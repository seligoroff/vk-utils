<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vk_posts') || Schema::hasColumn('vk_posts', 'views')) {
            return;
        }

        Schema::table('vk_posts', function (Blueprint $table) {
            $table->integer('views')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vk_posts') || ! Schema::hasColumn('vk_posts', 'views')) {
            return;
        }

        Schema::table('vk_posts', function (Blueprint $table) {
            $table->dropColumn('views');
        });
    }
};
