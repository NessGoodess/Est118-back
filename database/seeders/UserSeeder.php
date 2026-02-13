<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //$this->createDefaultAdminUser();
        $this->command->info('GG pa!!');
    }

    private function createDefaultAdminUser(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@est118.edu.mx'],
            [
                'name' => 'Administrador General',
                'password' => Hash::make('Admin123!'),
                'email_verified_at' => now(),
            ]
        );

        // Assign admin role to the admin user
        if (!$admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }

        $this->command->info('User created');
        $this->command->info('Admin user: admin@est118.edu.mx');
        $this->command->info('Password: Admin123!');
    }
}
