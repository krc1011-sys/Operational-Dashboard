<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What a deployment runs: `php artisan db:seed --force`.
 *
 * Roles and the first Admin are needed everywhere. The demo accounts are NOT - they
 * exist to preview the RBAC matrix during development and every one of them has the
 * password "password". Seeding those into an environment holding real buy prices and
 * margins would hand five logins, one of them Finance, to anyone who tried the
 * obvious. So production gets roles and the one Admin whose credentials came from the
 * environment, and nothing else.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
        ]);

        if (app()->environment('production')) {
            $this->command?->info('Production environment - demo users were not seeded.');

            return;
        }

        $this->call(DemoUsersSeeder::class);
    }
}
