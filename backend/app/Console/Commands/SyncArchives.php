<?php

namespace App\Console\Commands;

use App\Models\Archive;
use App\Models\BackupPlan;
use App\Services\Engines\BorgEngine;
use Illuminate\Console\Command;

class SyncArchives extends Command
{
    protected $signature = 'cellar:sync-archives
                            {--plan= : Sync a specific plan by ID}
                            {--dry-run : Show what would change without modifying the database}';

    protected $description = 'Reconcile the archives table with what actually exists in the borg repositories';

    public function handle(): int
    {
        $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));

        $query = BackupPlan::with('repository');

        if ($planId = $this->option('plan')) {
            $query->where('id', $planId);
        }

        $plans = $query->get();

        if ($plans->isEmpty()) {
            $this->warn('No backup plans found.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $totalAdded = 0;
        $totalRemoved = 0;
        $totalOk = 0;

        foreach ($plans as $plan) {
            $repo = $plan->repository;
            $repoPath = rtrim($repo->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

            $this->info("── Plan: {$plan->name} ({$plan->id})");

            if (! is_dir($repoPath)) {
                $this->warn("   Repository path does not exist: {$repoPath}");

                continue;
            }

            try {
                $borgArchives = $engine->listArchives($repoPath);
            } catch (\Throwable $e) {
                $this->error("   Failed to list borg archives: {$e->getMessage()}");

                continue;
            }

            $borgIds = collect($borgArchives)->pluck('archiveId')->toArray();
            $dbArchives = Archive::where('plan_id', $plan->id)->get();
            $dbIds = $dbArchives->pluck('archive_id')->toArray();

            // Archives in borg but not in DB → add
            $missingInDb = array_diff($borgIds, $dbIds);
            foreach ($missingInDb as $archiveId) {
                $borgInfo = collect($borgArchives)->firstWhere('archiveId', $archiveId);

                if ($dryRun) {
                    $this->line("   <fg=green>[+] Would add:</> {$archiveId}");
                } else {
                    try {
                        $info = $engine->getArchiveInfo($repoPath, $archiveId);

                        Archive::create([
                            'plan_id' => $plan->id,
                            'archive_id' => $archiveId,
                            'timestamp' => $borgInfo->timestamp
                                ? \Carbon\Carbon::parse($borgInfo->timestamp)
                                : now(),
                            'size_original' => $info->sizeOriginal ?? 0,
                            'size_dedup' => $info->sizeDedup ?? 0,
                            'size_compressed' => $info->sizeCompressed ?? 0,
                            'duration' => (int) ($info->durationSeconds ?? 0),
                            'file_count' => $info->fileCount ?? 0,
                            'metadata' => ['synced' => true],
                        ]);
                        $this->line("   <fg=green>[+] Added:</> {$archiveId}");
                    } catch (\Throwable $e) {
                        $this->error("   Failed to add {$archiveId}: {$e->getMessage()}");
                    }
                }
                $totalAdded++;
            }

            // Archives in DB but not in borg → remove
            $staleInDb = array_diff($dbIds, $borgIds);
            foreach ($staleInDb as $archiveId) {
                if ($dryRun) {
                    $this->line("   <fg=red>[-] Would remove:</> {$archiveId}");
                } else {
                    Archive::where('plan_id', $plan->id)
                        ->where('archive_id', $archiveId)
                        ->delete();
                    $this->line("   <fg=red>[-] Removed:</> {$archiveId}");
                }
                $totalRemoved++;
            }

            $matched = count(array_intersect($borgIds, $dbIds));
            $totalOk += $matched;

            if (empty($missingInDb) && empty($staleInDb)) {
                $this->line("   ✓ In sync ({$matched} archives)");
            }
        }

        $this->newLine();
        $label = $dryRun ? '(dry run) ' : '';
        $this->info("{$label}Summary: {$totalOk} ok, {$totalAdded} added, {$totalRemoved} removed");

        return self::SUCCESS;
    }
}
