<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['video_id', 'user_id'], 'video_favorites_video_user_unique');
            $table->index(['user_id', 'created_at'], 'video_favorites_user_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_favorites');
    }
};
