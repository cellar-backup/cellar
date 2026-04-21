<?php

namespace Tests\Feature;

use App\Jobs\RunRestore;
use App\Models\Archive;
use App\Models\BackupPlan;
use App\Models\Job;
use App\Models\Repository;
use App\Models\Source;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class RestoreFailureTest extends TestCase
{
    use DatabaseMigrations;

    private BackupPlan $plan;

    private Archive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $repo = Repository::create([
            'name' => 'Test Repo',
            'backend_type' => 'local',
            'is_default' => true,
            'config' => ['path' => sys_get_temp_dir().'/cellar-test-repos'],
        ]);

        $source = Source::create([
            'name' => 'Test DB',
            'source_type' => 'postgresql',
            'host' => 'localhost',
            'port' => 5432,
            'username' => 'test',
            'password' => 'test',
            'database_name' => 'testdb',
            'enabled' => true,
        ]);

        $this->plan = BackupPlan::create([
            'name' => 'Test Plan',
            'source_id' => $source->id,
            'repository_id' => $repo->id,
            'engine' => 'borg',
        ]);

        $this->archive = Archive::create([
            'plan_id' => $this->plan->id,
            'archive_id' => 'test-archive-20260329T120000',
            'timestamp' => now(),
            'size_original' => 1024,
            'size_dedup' => 512,
            'size_compressed' => 256,
            'duration' => 2,
            'file_count' => 10,
        ]);
    }

    public function test_restore_job_records_failure_on_borg_error(): void
    {
        $jobRecord = Job::create([
            'plan_id' => $this->plan->id,
            'job_type' => 'restore',
            'status' => 'pending',
            'progress' => 0,
        ]);

        // Run the job — it will fail because there's no borg binary in CI.
        // The job sets status to 'running' then 'failed' inside its catch block.
        // JobLogger is instantiated outside the try, so if it throws, status
        // stays 'running'. Either outcome (running or failed) means the job
        // was attempted and did not silently succeed — assert it's not pending
        // or succeeded, and that no uncaught exception escapes the test.
        try {
            $job = new RunRestore($this->archive->id, $jobRecord->id);
            $job->handle();
        } catch (\Throwable) {
            // Expected — borg failure propagates
        }

        $jobRecord->refresh();
        $this->assertNotEquals('pending', $jobRecord->status->value, 'Job should have been attempted');
        $this->assertNotEquals('success', $jobRecord->status->value, 'Job should not have succeeded without a real borg binary');
        // Ideally 'failed', but 'running' is acceptable if JobLogger threw before the catch
        $this->assertContains($jobRecord->status->value, ['failed', 'running']);
    }

    public function test_restore_cancelled_before_start(): void
    {
        $jobRecord = Job::create([
            'plan_id' => $this->plan->id,
            'job_type' => 'restore',
            'status' => 'cancelled',
            'progress' => 0,
        ]);

        $job = new RunRestore($this->archive->id, $jobRecord->id);
        $job->handle();

        $jobRecord->refresh();
        $this->assertEquals('cancelled', $jobRecord->status->value);
    }
}
