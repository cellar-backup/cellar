<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SeedDefaults extends Command
{
    protected $signature = 'cellar:seed-defaults';

    protected $description = 'Create default admin user and local repository if they do not exist';

    public function handle(): int
    {
        // 1. Default admin user
        if (User::count() === 0) {
            $name = config('cellar.admin_name', 'admin');
            $email = config('cellar.admin_email', 'admin@cellar.local');
            $password = config('cellar.admin_password', 'admin');

            User::create([
                'name' => $name,
                'username' => strtolower(str_replace(' ', '', $name)),
                'email' => $email,
                'password' => $password,  // Hashed automatically by User model's 'hashed' cast
            ]);

            $this->info("✓ Default admin user created ({$name})");
        } else {
            $this->line('  Admin user already exists, skipping.');
        }

        // 2. Default local repository
        if (! Repository::where('is_default', true)->exists()) {
            Repository::create([
                'name' => 'Default Local',
                'description' => 'Pre-configured local repository for backups.',
                'backend_type' => 'local',
                'is_default' => true,
                'config' => ['path' => '/data/repositories'],
            ]);

            $this->info('✓ Default local repository created.');
        } else {
            $this->line('  Default repository already exists, skipping.');
        }

        // 3. Default retention profile
        if (Profile::retention()->count() === 0) {
            Profile::create([
                'name' => 'Standard',
                'type' => 'retention',
                'is_default' => true,
                'config' => [
                    'keep_daily' => 7,
                    'keep_weekly' => 4,
                    'keep_monthly' => 6,
                    'keep_yearly' => 0,
                ],
            ]);

            $this->info('✓ Default retention profile created (Standard).');
        } else {
            $this->line('  Retention profiles already exist, skipping.');
        }

        // 4. Default schedule profile
        if (Profile::schedule()->count() === 0) {
            Profile::create([
                'name' => 'Daily at 02:00',
                'type' => 'schedule',
                'is_default' => true,
                'config' => ['cron' => '0 2 * * *'],
            ]);

            $this->info('✓ Default schedule profile created (Daily at 02:00).');
        } else {
            $this->line('  Schedule profiles already exist, skipping.');
        }

        return self::SUCCESS;
    }
}
