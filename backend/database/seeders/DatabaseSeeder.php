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
        User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Admin',
                'username' => 'admin',
                'email' => 'admin@cellar.local',
                'password' => 'changeme', // The User model's 'hashed' cast auto-hashes
            ],
        );
    }
}
