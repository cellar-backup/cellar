<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radar_clusters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('kubeconfig')->nullable();  // encrypted YAML content
            $table->string('context')->nullable();
            $table->string('default_namespace')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
        });

        // Add cluster_id FK to radar_ignores
        Schema::table('radar_ignores', function (Blueprint $table) {
            $table->uuid('cluster_id')->nullable()->after('id');
            $table->foreign('cluster_id')->references('id')->on('radar_clusters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('radar_ignores', function (Blueprint $table) {
            $table->dropForeign(['cluster_id']);
            $table->dropColumn('cluster_id');
        });

        Schema::dropIfExists('radar_clusters');
    }
};
