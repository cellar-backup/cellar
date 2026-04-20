<?php

namespace Database\Seeders;

use App\Models\Archive;
use App\Models\BackupPlan;
use App\Models\Repository;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Repository ──
        $repo = Repository::firstOrCreate(
            ['name' => 'local-storage'],
            [
                'description' => 'Local backup storage',
                'backend_type' => 'local',
                'status' => 'online',
                'is_default' => true,
                'config' => ['path' => '/var/backups/cellar'],
                'capacity_bytes' => 500 * 1024 * 1024 * 1024, // 500 GB
                'used_bytes' => 184 * 1024 * 1024 * 1024, // 184 GB
            ],
        );

        // ── Sources (databases) ──
        $sources = [
            [
                'name' => 'northwind-prod',
                'source_type' => 'postgresql',
                'host' => 'db-prod.internal',
                'port' => 5432,
                'username' => 'cellar_ro',
                'database_name' => 'northwind',
                'enabled' => true,
                'is_reachable' => true,
                'last_checked_at' => now(),
                'notes' => 'Primary production database',
            ],
            [
                'name' => 'northwind-staging',
                'source_type' => 'postgresql',
                'host' => 'db-staging.internal',
                'port' => 5432,
                'username' => 'cellar_ro',
                'database_name' => 'northwind_staging',
                'enabled' => true,
                'is_reachable' => true,
                'last_checked_at' => now(),
            ],
            [
                'name' => 'auth-service',
                'source_type' => 'postgresql',
                'host' => 'db-prod.internal',
                'port' => 5432,
                'username' => 'cellar_ro',
                'database_name' => 'auth',
                'enabled' => true,
                'is_reachable' => true,
                'last_checked_at' => now(),
            ],
            [
                'name' => 'analytics-warehouse',
                'source_type' => 'mysql',
                'host' => 'analytics.internal',
                'port' => 3306,
                'username' => 'backup_user',
                'database_name' => 'analytics',
                'enabled' => true,
                'is_reachable' => true,
                'last_checked_at' => now(),
            ],
            [
                'name' => 'billing-ledger',
                'source_type' => 'mysql',
                'host' => 'billing-db.internal',
                'port' => 3306,
                'username' => 'cellar',
                'database_name' => 'billing',
                'enabled' => true,
                'is_reachable' => true,
                'last_checked_at' => now(),
            ],
            [
                'name' => 'search-index',
                'source_type' => 'mongodb',
                'host' => 'mongo.internal',
                'port' => 27017,
                'username' => 'backup',
                'database_name' => 'search',
                'enabled' => false,
                'is_reachable' => true,
                'last_checked_at' => now()->subHours(6),
                'notes' => 'Paused — rebuilding index',
            ],
            [
                'name' => 'dev-local',
                'source_type' => 'postgresql',
                'host' => 'localhost',
                'port' => 5432,
                'username' => 'postgres',
                'database_name' => 'dev',
                'enabled' => true,
                'is_reachable' => true,
                'last_checked_at' => now(),
            ],
            [
                'name' => 'legacy-v3',
                'source_type' => 'mysql',
                'host' => 's3-archive.internal',
                'port' => 3306,
                'username' => 'readonly',
                'database_name' => 'legacy_v3',
                'enabled' => false,
                'is_reachable' => null,
                'last_checked_at' => null,
                'notes' => 'Archived — no longer in use',
            ],
        ];

        foreach ($sources as $sourceData) {
            $source = Source::firstOrCreate(
                ['name' => $sourceData['name']],
                $sourceData,
            );

            // Create a backup plan for each source
            $plan = BackupPlan::firstOrCreate(
                ['source_id' => $source->id, 'name' => "Daily {$source->name}"],
                [
                    'repository_id' => $repo->id,
                    'engine' => 'restic',
                    'status' => $source->enabled ? 'healthy' : 'idle',
                    'schedule_cron' => '0 */3 * * *',
                    'schedule_enabled' => $source->enabled,
                    'retention_policy' => [
                        'keep_daily' => 7,
                        'keep_weekly' => 4,
                        'keep_monthly' => 6,
                    ],
                    'compression' => 'zstd',
                    'encryption' => true,
                ],
            );

            // Create archives (backups) — more for prod, fewer for others
            $archiveCount = match ($source->name) {
                'northwind-prod' => 20,
                'northwind-staging' => 12,
                'auth-service' => 14,
                'analytics-warehouse' => 8,
                'billing-ledger' => 10,
                'search-index' => 4,
                'dev-local' => 3,
                'legacy-v3' => 2,
                default => 5,
            };

            $baseSize = match ($source->name) {
                'northwind-prod' => 2.1 * 1024 * 1024 * 1024,
                'analytics-warehouse' => 6.2 * 1024 * 1024 * 1024,
                'billing-ledger' => 480 * 1024 * 1024,
                'auth-service' => 120 * 1024 * 1024,
                'northwind-staging' => 380 * 1024 * 1024,
                'search-index' => 720 * 1024 * 1024,
                'dev-local' => 42 * 1024 * 1024,
                'legacy-v3' => 1.2 * 1024 * 1024 * 1024,
                default => 500 * 1024 * 1024,
            };

            $now = Carbon::now();

            for ($i = 0; $i < $archiveCount; $i++) {
                $timestamp = $now->copy()->subHours($i * 3 + rand(0, 2))->subMinutes(rand(0, 59));
                $sizeVariance = $baseSize * (0.95 + (rand(0, 10) / 100));
                $compressed = $sizeVariance * 0.4;
                $dedup = $sizeVariance * 0.6;

                $labels = [
                    'pre-migration snapshot',
                    'release v4.12.0',
                    'before hotfix',
                    'schema: add_indexes',
                    'quarterly archive',
                    '', '', '', '', '', // most have no label
                ];

                Archive::firstOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'archive_id' => sprintf('bk_%s_%04d', substr($source->name, 0, 4), $i + 1),
                    ],
                    [
                        'timestamp' => $timestamp,
                        'size_original' => (int) $sizeVariance,
                        'size_dedup' => (int) $dedup,
                        'size_compressed' => (int) $compressed,
                        'duration' => rand(8, 120),
                        'file_count' => rand(80, 500),
                        'keep_forever' => $i === 0 && rand(0, 3) === 0,
                        'tags' => $i % 5 === 0 ? ['automated'] : null,
                        'notes' => $labels[array_rand($labels)],
                        'created_at' => $timestamp,
                    ],
                );
            }
        }

        $this->command->info('Demo data seeded: 8 sources, backup plans, and archives.');
    }
}
