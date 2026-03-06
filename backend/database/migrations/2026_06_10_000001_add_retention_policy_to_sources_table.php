<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->json('retention_policy')->nullable()->after('extra_config');
        });

        // Migrate: copy each source's retention from its first backup plan
        $sources = DB::table('sources')->pluck('id');
        foreach ($sources as $sourceId) {
            $plan = DB::table('backup_plans')
                ->where('source_id', $sourceId)
                ->whereNotNull('retention_policy')
                ->first();

            if ($plan && $plan->retention_policy) {
                DB::table('sources')
                    ->where('id', $sourceId)
                    ->update(['retention_policy' => $plan->retention_policy]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('retention_policy');
        });
    }
};
