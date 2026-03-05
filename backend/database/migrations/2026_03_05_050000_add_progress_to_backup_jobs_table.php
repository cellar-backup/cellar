<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('backup_jobs', 'progress')) {
            Schema::table('backup_jobs', function (Blueprint $table) {
                $table->unsignedTinyInteger('progress')->default(0)->after('error_message');
            });
        }
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    }
};
