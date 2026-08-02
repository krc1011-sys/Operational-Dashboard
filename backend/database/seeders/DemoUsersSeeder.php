<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// Demo users for previewing the RBAC matrix during development. Password for all: password
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['Admin', 'Finance', 'Sales', 'Procurement', 'Warehouse'];
        foreach ($roles as $role) {
            $email = strtolower($role).'@demo.local';
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $role.' Demo', 'password' => Hash::make('password')]
            );
            $user->syncRoles([$role]);
        }
    }
}
