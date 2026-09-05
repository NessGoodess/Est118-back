<?php

namespace Database\Seeders\ServiceUser;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         User::firstOrCreate(
            [
                'email' => 'nfc-service@example.com',
            ],
            [
                'name' => 'Service User',
                'password' => bcrypt(Str::random(40)), // Never used
                'email_verified_at' => now(),
            ]
        );
    $this->command->info('Service user created');
    }
}
