<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class PermissionsAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:admin';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reasign all permissions to admin user';

    /**
     * Execute the console command.
     * 
     * Example: php artisan permissions:admin
     */
    public function handle(): void
    {
        if (!$this->confirm('Are you sure you want to continue?')) {
            $this->error('Operation cancelled');
            return;
        }
        $adminRole = Role::where('name', 'admin')->first();
        $preEnrollmentAdminRole = Role::where('name', 'pre-enrollment-admin')->first();
        $adminRole->givePermissionTo(Permission::all());
        $preEnrollmentAdminRole->givePermissionTo('view pre-enrollments', 'edit pre-enrollments', 'manage admission cycles');
        $this->info('Permissions reassigned successfully');
    }
}
