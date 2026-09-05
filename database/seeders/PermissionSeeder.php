<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Users
            'create users',
            'edit users',
            'delete users',
            'view users',

            // Students
            'view students',
            'create students',
            'edit students',
            'delete students',
            'view student photos',
            'manage student photos',

            // Admissions
            'view pre-enrollments',
            'create pre-enrollments',
            'edit pre-enrollments',
            'delete pre-enrollments',
            'view admission enrollment',
            'edit admission enrollment',
            'manage admission cycles',
            'manage re-enrollment',

            // Academic years (school calendar cycles)
            'view academic years',
            'create academic years',
            'delete academic years',

            // General attendance (NFC)
            'view general attendance',
            'manage nfc readings',
            'edit general attendance',

            // Class attendance / reports (sidebar)
            'view attendance',
            'view reports',

            // Announcements
            'create announcements',
            'view announcements',
            'edit announcements',
            'delete announcements',

            // Galleries (photo albums)
            'create galleries',
            'view galleries',
            'edit galleries',
            'delete galleries',

            // Events
            'create events',
            'view events',
            'edit events',
            'delete events',

            // Misc used by UI
            'manage settings',
            'view groups',
            'create profile',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Legacy name from older seeds — keep API on manage nfc readings
        $legacy = Permission::where('name', 'manage general attendance')->first();
        if ($legacy) {
            $legacy->delete();
        }

        $adminRole = Role::findOrCreate('admin', 'web');
        Role::findOrCreate('user', 'web');
        Role::findOrCreate('pre-enrollment-admin', 'web');

        $adminRole->syncPermissions(Permission::all());

        // Roles that managed the whole pre-enrollment flow before the split
        // keep access to the admission enrollment process.
        $legacyAdmissionRoles = Role::query()
            ->whereHas('permissions', fn ($query) => $query->whereIn('name', [
                'edit pre-enrollments',
                'manage admission cycles',
            ]))
            ->get();

        foreach ($legacyAdmissionRoles as $role) {
            $role->givePermissionTo([
                'create pre-enrollments',
                'delete pre-enrollments',
                'view admission enrollment',
                'edit admission enrollment',
            ]);
        }

        // Roles that already publish announcements manage the rest of the CMS
        // (galleries and events) without a manual re-assignment.
        $contentRoles = Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'create announcements'))
            ->get();

        foreach ($contentRoles as $role) {
            $role->givePermissionTo([
                'create galleries',
                'view galleries',
                'edit galleries',
                'delete galleries',
                'create events',
                'view events',
                'edit events',
                'delete events',
            ]);
        }

        $this->command->info('Permissions and roles created successfully');
        $this->command->info('Admin role synced with all permissions.');
        $this->command->warn('Re-run or adjust other roles with: php artisan permissions:admin');
    }
}
