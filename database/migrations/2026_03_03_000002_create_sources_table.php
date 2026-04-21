<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255)->default('');
            $table->string('source_type', 20);
            $table->string('host', 255)->default('');
            $table->integer('port')->nullable();
            $table->string('username', 255)->default('');
            $table->text('password')->default('');
            $table->string('database_name', 255)->default('');
            $table->string('path', 1000)->default('');
            $table->boolean('enabled')->default(true);
            $table->text('notes')->default('');
            $table->json('extra_config')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
