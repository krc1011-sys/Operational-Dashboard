<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The first Admin, from ADMIN_EMAIL / ADMIN_PASSWORD.
 *
 * All the work is in `operon:bootstrap-admin`; this exists so that the single
 * `php artisan db:seed --force` in the deploy pipeline covers the admin account as
 * well as the roles, with no second command to forget.
 *
 * It is SKIPPED, not failed, when ADMIN_EMAIL is absent - `db:seed` is also how a
 * developer sets up a local database, and there is no first-Admin problem to solve
 * there. When the variable IS set, a bad value stops the seed rather than being
 * ignored, so a mistyped secret fails the deployment instead of silently leaving the
 * environment without its only way in.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (blank(config('operon.admin.email'))) {
            $this->command?->warn('ADMIN_EMAIL is not set - skipping the first-Admin bootstrap.');

            return;
        }

        $exit = Artisan::call('operon:bootstrap-admin', [], $this->command?->getOutput());

        if ($exit !== 0) {
            throw new \RuntimeException('operon:bootstrap-admin failed - see the errors above.');
        }
    }
}
