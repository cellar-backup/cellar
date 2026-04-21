<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255)->unique();
            $table->unsignedInteger('version')->default(1);
            $table->text('description')->default('');
            $table->text('backup_command');
            $table->text('restore_command');
            $table->text('health_check')->default('');
            $table->json('env_vars')->default('[]');
            $table->boolean('stream_to_engine')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_documents');
    }
};
