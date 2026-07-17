<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => env('CPORTER_ADMIN_EMAIL', 'admin@cporter.local')],
            [
                'name' => 'cPorter Admin',
                'password' => Hash::make(env('CPORTER_ADMIN_PASSWORD', 'password')),
            ],
        );
    }
}
