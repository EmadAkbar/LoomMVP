<?php

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('cloudflare_uid')->nullable()->index();
            $table->string('upload_uid')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default(VideoStatus::Uploading->value)->index();
            $table->string('privacy')->default(VideoPrivacy::Public->value)->index();
            $table->string('password_hash')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('playback_url')->nullable();
            $table->text('download_url')->nullable();
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('processing_percentage')->default(0);
            $table->json('cloudflare_meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
