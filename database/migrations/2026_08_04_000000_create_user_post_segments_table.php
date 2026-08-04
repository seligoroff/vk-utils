<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_post_segments', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->string('owner_id', 32);
            $table->unsignedInteger('post_id');
            $table->string('segment', 16);                       // core | hidden | open
            $table->unsignedInteger('friends_in_likers_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['owner_id', 'post_id', 'user_id'], 'ups_owner_post_user');
            $table->index(['owner_id', 'user_id'], 'idx_owner_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_post_segments');
    }
};
