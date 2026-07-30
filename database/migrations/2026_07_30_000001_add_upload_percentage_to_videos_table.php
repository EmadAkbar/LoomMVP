<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split upload progress out of `processing_percentage`.
 *
 * The two are different stages of different things — bytes leaving the browser
 * versus Cloudflare transcoding — but they shared one column, so the upload
 * finishing at 100 was immediately overwritten by Cloudflare's first
 * "inprogress" webhook at 15. The bar visibly ran to 100%, snapped back to 15%
 * and climbed again. Separate columns make each stage monotonic on its own and
 * let the UI say which stage it is showing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->unsignedTinyInteger('upload_percentage')->default(0)->after('processing_percentage');
        });

        // Anything already uploaded has, by definition, finished uploading —
        // otherwise every existing row would render as "0% uploaded".
        DB::table('videos')->whereIn('status', ['processing', 'ready'])->update(['upload_percentage' => 100]);

        // A row still `uploading` carries its real upload progress in the old
        // shared column, so move it across rather than resetting it to zero.
        DB::table('videos')->where('status', 'uploading')->update([
            'upload_percentage' => DB::raw('processing_percentage'),
        ]);
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('upload_percentage');
        });
    }
};
