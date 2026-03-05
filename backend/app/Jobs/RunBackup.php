<?php

namespace App\Jobs;

use App\Models\Archive;
use App\Models\BackupPlan;
use App\Models\Job;
use App\Models\RadarCluster;
use App\Services\DatabaseDumper;
use App\Services\Engines\BorgEngine;
use App\Services\JobLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RunBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 7200;

    public function __construct(public string $planId) {}

    public function handle(): void
    {
        $plan = BackupPlan::with(['source', 'repository'])->findOrFail($this->planId);
        $source = $plan->source;
        $repo = $plan->repository;

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'backup',
            'status' => 'running',
            'started_at' => now(),
            'progress' => 5,
        ]);

        $plan->update(['status' => 'running']);

        $tmpDir = null;
        $log = new JobLogger($job);

        try {
            $log->section('Initializing backup');
            $log->line("Plan: {$plan->name}");
            $log->line("Source: {$source->display_label} ({$source->source_type->value})");
            $log->line("Repository: {$repo->name}");

            $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));
            $repoPath = rtrim($repo->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

            // Ensure repo is initialized
            if (! is_dir($repoPath)) {
                $log->line("Initializing new borg repository at {$repoPath}");
                $engine->initialize($repoPath);
            }

            $job->update(['progress' => 10]);

            // Prepare source
            if ($source->getIsDatabase()) {
                $tmpDir = sys_get_temp_dir().'/cellar_dump_'.Str::random(8);
                mkdir($tmpDir, 0755, true);

                $job->update(['progress' => 15]);
                $log->section('Database dump');
                $log->line("Dumping {$source->source_type->value} database: {$source->database_name}");
                $log->line("Host: {$source->host}:{$source->port}");

                $dbConfig = [
                    'host' => $source->host,
                    'port' => $source->port,
                    'username' => $source->username,
                    'password' => $source->password,
                    'database_name' => $source->database_name,
                ];

                $dumpResult = null;
                $k8s = $source->extra_config['kubernetes'] ?? null;

                // Try kubectl exec for K8s-sourced databases (runs dump inside the pod)
                if ($k8s && ! empty($k8s['cluster_id']) && ! empty($k8s['namespace']) && ! empty($k8s['app_name'])) {
                    $dumpResult = $this->tryKubectlDump($source, $dbConfig, $tmpDir, $k8s, $log);
                }

                // Fall back to direct network connection
                if (! $dumpResult || ! $dumpResult->success) {
                    if ($dumpResult) {
                        $log->line("kubectl exec failed, trying direct connection...");
                    }

                    $dumpResult = DatabaseDumper::dump(
                        $source->source_type->value,
                        $dbConfig,
                        $tmpDir,
                    );
                }

                if (! $dumpResult->success) {
                    $log->line("FAILED: {$dumpResult->message}");
                    throw new \RuntimeException('Database dump failed: '.$dumpResult->message);
                }

                $log->line("Dump completed: {$dumpResult->dumpPath} ({$dumpResult->sizeBytes} bytes)");
                if (str_contains($dumpResult->message, 'kubectl exec')) {
                    $log->line("Method: kubectl exec (in-pod dump)");
                } else {
                    $log->line("Method: direct network connection");
                }
                $sourcePaths = [$tmpDir];
                $job->update(['progress' => 30]);
            } else {
                $log->section('Filesystem source');
                $log->line("Path: {$source->path}");
                $sourcePaths = [$source->path];
                $job->update(['progress' => 30]);
            }

            // Build archive name
            $safeName = Str::slug($source->display_label, '_');
            $archiveName = $safeName.'-'.now()->format('Ymd\THis');

            // Run backup
            $log->section('Borg backup');
            $log->line("Archive: {$archiveName}");
            $log->line("Compression: ".($plan->compression ?? 'lz4'));

            $result = $engine->backup(
                $repoPath,
                $sourcePaths,
                $archiveName,
                compression: $plan->compression ?? 'lz4',
            );

            $job->update(['progress' => 85]);
            $log->line("Backup completed successfully");
            $log->line("Original size: {$result->sizeOriginal}");
            $log->line("Deduplicated size: {$result->sizeDedup}");
            $log->line("Compressed size: {$result->sizeCompressed}");
            $log->line("Files: {$result->fileCount}");
            $log->line("Duration: {$result->durationSeconds}s");

            // Create archive record
            Archive::create([
                'plan_id' => $plan->id,
                'archive_id' => $result->archiveId,
                'timestamp' => now(),
                'size_original' => $result->sizeOriginal,
                'size_dedup' => $result->sizeDedup,
                'size_compressed' => $result->sizeCompressed,
                'duration' => (int) $result->durationSeconds,
                'file_count' => $result->fileCount,
                'metadata' => ['message' => $result->message],
            ]);

            $job->update([
                'status' => 'success',
                'finished_at' => now(),
                'progress' => 100,
                'metadata' => [
                    'archive_id' => $result->archiveId,
                    'size_original' => $result->sizeOriginal,
                    'size_dedup' => $result->sizeDedup,
                    'duration' => $result->durationSeconds,
                ],
            ]);

            $plan->update(['status' => 'healthy', 'last_run' => now()]);
            $log->section('Completed');
            $log->line('Backup finished successfully.');
            $log->close();

        } catch (\Throwable $e) {
            $log->error($e);
            $log->close();

            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => Str::limit($e->getMessage(), 2000),
            ]);

            $plan->update(['status' => 'failed']);

            throw $e;
        } finally {
            // Cleanup temp dump directory
            if ($tmpDir && is_dir($tmpDir)) {
                $this->removeDirectory($tmpDir);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    /**
     * Attempt to dump a database via kubectl exec (inside the K8s pod).
     *
     * This is the preferred method for K8s-sourced databases because it
     * runs the dump inside the container where the DB allows localhost
     * connections, bypassing network/auth restrictions.
     */
    private function tryKubectlDump(
        \App\Models\Source $source,
        array $dbConfig,
        string $tmpDir,
        array $k8s,
        JobLogger $log,
    ): ?\App\Services\DumpResult {
        try {
            $cluster = RadarCluster::find($k8s['cluster_id']);
            if (! $cluster) {
                $log->line("K8s cluster {$k8s['cluster_id']} not found, skipping kubectl exec.");
                return null;
            }

            $kubectlPath = config('cellar.kubectl_path', '/usr/local/bin/kubectl');
            $tempKubeconfig = $cluster->writeKubeconfigTempFile();

            try {
                $kubectlConfig = [
                    'kubectl_path' => $kubectlPath,
                    'kubeconfig' => $tempKubeconfig,
                    'context' => $cluster->context,
                    'namespace' => $k8s['namespace'],
                    'pod' => null, // will be resolved
                ];

                // Find a running pod for this app
                $log->line("Looking for running pod (app={$k8s['app_name']}) in namespace {$k8s['namespace']}...");
                $podName = DatabaseDumper::findKubectlPod($kubectlConfig, $k8s['app_name']);

                if (! $podName) {
                    $log->line("No running pod found for app={$k8s['app_name']}.");
                    return null;
                }

                $log->line("Found pod: {$podName}");
                $kubectlConfig['pod'] = $podName;

                $log->line("Dumping via kubectl exec into {$podName}...");
                return DatabaseDumper::dumpViaKubectl(
                    $source->source_type->value,
                    $dbConfig,
                    $tmpDir,
                    $kubectlConfig,
                );
            } finally {
                // Cleanup temp kubeconfig
                if ($tempKubeconfig && file_exists($tempKubeconfig)) {
                    unlink($tempKubeconfig);
                }
            }
        } catch (\Throwable $e) {
            $log->line("kubectl exec dump error: {$e->getMessage()}");
            return null;
        }
    }
}
