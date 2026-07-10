<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE videos MODIFY privacy VARCHAR(255) NOT NULL DEFAULT 'public'");
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE videos ALTER COLUMN privacy SET DEFAULT 'public'");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE videos MODIFY privacy VARCHAR(255) NOT NULL DEFAULT 'private'");
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE videos ALTER COLUMN privacy SET DEFAULT 'private'");
        }
    }
};
