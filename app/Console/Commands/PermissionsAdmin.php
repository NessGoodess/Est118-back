<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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
    protected $description = 'Reassign all permissions to admin roles';

    /**
     * Execute the console command.
     *
     * Example: php artisan permissions:admin
     */
    public function handle(): void
    {
        if (! $this->confirm('Are you sure you want to continue?')) {
            $this->error('Operation cancelled');

            return;
        }

        $adminRole = Role::where('name', 'admin')->first();
 
        if (! $adminRole) {
            $this->error('Role "admin" not found');

            return;
        }

        $adminRole->givePermissionTo(Permission::all());

        $this->info('Permissions reassigned successfully');
    }
}
