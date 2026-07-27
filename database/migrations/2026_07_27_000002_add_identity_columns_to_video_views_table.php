<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('video_views', function (Blueprint $table) {
            $table->string('viewer_device_id')->nullable()->after('viewer_agent');
            $table->string('viewer_fingerprint', 64)->nullable()->after('viewer_device_id');
            $table->index(['video_id', 'viewer_fingerprint'], 'video_views_video_fingerprint_idx');
        });
    }

    public function down(): void
    {
        Schema::table('video_views', function (Blueprint $table) {
            $table->dropIndex('video_views_video_fingerprint_idx');
            $table->dropColumn(['viewer_device_id', 'viewer_fingerprint']);
        });
    }
};
