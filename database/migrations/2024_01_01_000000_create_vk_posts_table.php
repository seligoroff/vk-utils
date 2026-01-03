<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVkPostsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vk_posts', function (Blueprint $table) {
            $table->id();
            $table->integer('post_id')->index();
            $table->string('owner_id', 50)->index();
            $table->integer('timestamp')->index();
            $table->dateTime('date');
            $table->text('text')->nullable();
            $table->integer('likes')->default(0);
            $table->integer('reposts')->default(0);
            $table->integer('comments')->default(0);
            $table->string('url', 500);
            $table->timestamps();
            
            // Уникальный индекс для предотвращения дублей
            $table->unique(['owner_id', 'post_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vk_posts');
    }
}



