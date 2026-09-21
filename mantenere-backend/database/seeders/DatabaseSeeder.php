<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $rootRole = Role::whereIn('name', ['root', 'Root'])->first();
        $adminRole = Role::whereIn('name', ['admin', 'Admin'])->first();

        if ($rootRole) {
            User::updateOrCreate(
                ['email' => 'root@mantenere.com'],
                [
                    'name' => 'Root Access',
                    'password' => Hash::make('MantenereRoot2026!'),
                    'role_id' => $rootRole->id,
                    'active' => 1,
                ]
            );
        }

        if ($adminRole) {
            User::updateOrCreate(
                ['email' => 'admin@mantenere.com'],
                [
                    'name' => 'Admin Base',
                    'password' => Hash::make('AdminBase2026!'),
                    'role_id' => $adminRole->id,
                    'active' => 1,
                ]
            );
        }
    }
}
