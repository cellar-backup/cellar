<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Use Artisan command which handles secure password generation.
        // Direct seeding with fixed passwords is intentionally removed.
        Artisan::call('cellar:seed-defaults');
    }
}
