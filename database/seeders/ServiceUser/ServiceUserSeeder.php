<?php

namespace Database\Seeders\ServiceUser;

use App\Models\User;
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
                'email' => 'nfc-service@est118.edu.mx',
            ],
            [
                'name' => 'NFC Service User',
                'password' => bcrypt(Str::random(40)),
                'email_verified_at' => now(),
            ]
        );

        User::firstOrCreate(
            [
                'email' => 'print-agent@est118.edu.mx',
            ],
            [
                'name' => 'ZC300 Print Agent',
                'password' => bcrypt(Str::random(40)),
                'email_verified_at' => now(),
            ]
        );

        $this->command?->info('Service users created (NFC + ZC300 print agent)');
    }
}
