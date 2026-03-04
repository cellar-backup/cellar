<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->foreignUuid('source_id')->constrained('sources')->cascadeOnDelete();
            $table->foreignUuid('repository_id')->constrained('repositories')->restrictOnDelete();
            $table->string('engine', 20)->default('borg');
            $table->string('status', 20)->default('idle');
            $table->string('schedule_cron', 100)->default('0 2 * * *');
            $table->boolean('schedule_enabled')->default(true);
            $table->timestamp('next_run')->nullable();
            $table->timestamp('last_run')->nullable();
            $table->json('retention_policy')->default('{}');
            $table->string('compression', 20)->default('lz4');
            $table->string('encryption', 20)->default('none');
            $table->text('pre_hook')->default('');
            $table->text('post_hook')->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_plans');
    }
};
