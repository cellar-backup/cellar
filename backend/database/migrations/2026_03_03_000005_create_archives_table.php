<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('backup_plans')->cascadeOnDelete();
            $table->string('archive_id', 255);
            $table->timestamp('timestamp');
            $table->bigInteger('size_original')->default(0);
            $table->bigInteger('size_dedup')->default(0);
            $table->bigInteger('size_compressed')->default(0);
            $table->integer('duration')->nullable()->comment('Duration in seconds');
            $table->bigInteger('file_count')->default(0);
            $table->json('metadata')->default('{}');
            $table->boolean('keep_forever')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['plan_id', 'archive_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archives');
    }
};
