<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('channel_type', 20);
            $table->json('config')->default('{}');
            $table->json('events_filter')->default('[]');
            $table->boolean('enabled')->default(true);
            $table->foreignUuid('backup_plan_id')->nullable()->constrained('backup_plans')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
