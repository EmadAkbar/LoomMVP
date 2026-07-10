<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'videos_user_created_at_idx');
            $table->index(['status', 'privacy', 'created_at'], 'videos_status_privacy_created_at_idx');
        });

        Schema::table('video_comments', function (Blueprint $table) {
            $table->index(['video_id', 'timestamp_seconds'], 'video_comments_video_timestamp_idx');
            $table->index(['video_id', 'created_at'], 'video_comments_video_created_at_idx');
        });

        Schema::table('video_shares', function (Blueprint $table) {
            $table->index(['video_id', 'is_active', 'expires_at'], 'video_shares_video_active_expires_idx');
        });

        Schema::table('video_views', function (Blueprint $table) {
            $table->index(['video_id', 'created_at'], 'video_views_video_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex('videos_user_created_at_idx');
            $table->dropIndex('videos_status_privacy_created_at_idx');
        });

        Schema::table('video_comments', function (Blueprint $table) {
            $table->dropIndex('video_comments_video_timestamp_idx');
            $table->dropIndex('video_comments_video_created_at_idx');
        });

        Schema::table('video_shares', function (Blueprint $table) {
            $table->dropIndex('video_shares_video_active_expires_idx');
        });

        Schema::table('video_views', function (Blueprint $table) {
            $table->dropIndex('video_views_video_created_at_idx');
        });
    }
};
