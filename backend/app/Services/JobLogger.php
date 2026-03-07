<?php

namespace App\Services;

use App\Models\Job;

/**
 * File-based logger for backup job output.
 *
 * Stores per-job log files outside the database to keep the DB lean.
 * Each job gets its own log file at /var/log/cellar/jobs/{job_id}.log.
 */
class JobLogger
{
    private string $path;

    private $handle;

    public function __construct(private Job $job)
    {
        $dir = self::logDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->path = "{$dir}/{$job->id}.log";
        $this->handle = fopen($this->path, 'a');

        // Persist path in DB
        $job->update(['log_path' => $this->path]);
    }

    /**
     * Base directory for job logs.
     */
    public static function logDir(): string
    {
        return config('cellar.log_dir', '/var/log/cellar/jobs');
    }

    /**
     * Write a section header to the log.
     */
    public function section(string $label): void
    {
        $ts = now()->toIso8601String();
        fwrite($this->handle, "\n=== [{$ts}] {$label} ===\n");
    }

    /**
     * Log a line of text.
     */
    public function line(string $text): void
    {
        fwrite($this->handle, "{$text}\n");
    }

    /**
     * Log process stdout + stderr from a command result.
     */
    public function process(string $label, object $result): void
    {
        $this->section($label);

        $stdout = method_exists($result, 'output') ? $result->output() : ($result->output ?? '');
        $stderr = method_exists($result, 'errorOutput') ? $result->errorOutput() : ($result->errorOutput ?? '');
        $exitCode = method_exists($result, 'exitCode') ? $result->exitCode() : ($result->exitCode ?? null);

        if ($stdout) {
            fwrite($this->handle, "[stdout]\n{$stdout}\n");
        }
        if ($stderr) {
            fwrite($this->handle, "[stderr]\n{$stderr}\n");
        }
        if ($exitCode !== null) {
            fwrite($this->handle, "[exit_code] {$exitCode}\n");
        }
    }

    /**
     * Log an exception.
     */
    public function error(\Throwable $e): void
    {
        $this->section('ERROR');
        fwrite($this->handle, "{$e->getMessage()}\n{$e->getTraceAsString()}\n");
    }

    /**
     * Close the log file handle.
     */
    public function close(): void
    {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Read the log file content for a given job.
     */
    public static function read(Job $job, int $maxBytes = 512000): ?string
    {
        $path = $job->log_path;
        if (! $path || ! file_exists($path)) {
            return null;
        }

        $size = filesize($path);
        if ($size <= $maxBytes) {
            return file_get_contents($path);
        }

        // Return the tail if the file is too large
        $handle = fopen($path, 'r');
        fseek($handle, -$maxBytes, SEEK_END);
        $content = "... (truncated, showing last {$maxBytes} bytes) ...\n".fread($handle, $maxBytes);
        fclose($handle);

        return $content;
    }

    /**
     * Delete log files older than the given number of days.
     */
    public static function cleanup(int $retentionDays = 30): int
    {
        $dir = self::logDir();
        if (! is_dir($dir)) {
            return 0;
        }

        $cutoff = now()->subDays($retentionDays)->timestamp;
        $deleted = 0;

        foreach (glob("{$dir}/*.log") as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function __destruct()
    {
        $this->close();
    }
}
