<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vk_posts', function (Blueprint $table) {
            // Количество просмотров (из wall.get, поле views.count)
            // VK API всегда возвращает views для каждого поста
            $table->integer('views')->default(0)->after('comments');

            // Охват (из stats.getPostReach — только для сообществ)
            // reach_total      — общий охват (уникальные пользователи)
            // reach_subscribers — охват среди подписчиков
            // reach_viral      — виральный охват
            // reach_ads        — рекламный охват
            $table->integer('reach_total')->nullable()->after('views');
            $table->integer('reach_subscribers')->nullable()->after('reach_total');
            $table->integer('reach_viral')->nullable()->after('reach_subscribers');
            $table->integer('reach_ads')->nullable()->after('reach_viral');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vk_posts', function (Blueprint $table) {
            $table->dropColumn([
                'views',
                'reach_total',
                'reach_subscribers',
                'reach_viral',
                'reach_ads',
            ]);
        });
    }
};
