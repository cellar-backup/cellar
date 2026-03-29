<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
        \Illuminate\Support\Facades\Artisan::call('cellar:seed-defaults');
    }
}
