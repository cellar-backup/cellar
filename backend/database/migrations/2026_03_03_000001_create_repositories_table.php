<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255)->unique();
            $table->text('description')->default('');
            $table->string('backend_type', 20);
            $table->string('status', 20)->default('unknown');
            $table->boolean('is_default')->default(false);
            $table->json('config')->default('{}');
            $table->bigInteger('capacity_bytes')->nullable();
            $table->bigInteger('used_bytes')->default(0);
            $table->timestamp('last_checked')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
