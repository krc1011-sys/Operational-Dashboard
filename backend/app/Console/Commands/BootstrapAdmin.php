<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Creates the first Admin on a fresh environment, from environment variables.
 *
 * There is no way into OperON without an account, and no way to create the first
 * account from inside the app - so a brand new deployment needs exactly one thing
 * handed to it from outside. That is this command, and it takes it from the
 * environment rather than from the repository:
 *
 *   ADMIN_EMAIL     the sign-in address for the first Admin
 *   ADMIN_PASSWORD  its password
 *   MONEY_PIN       the PIN gating every cost / price / margin screen (§S)
 *
 * NOTHING here is defaulted to a value that would work. A missing or placeholder
 * secret stops the command instead of quietly creating an account someone else can
 * guess, because the data behind this login is real buy prices and real margins.
 *
 * Safe to run repeatedly - it is what `db:seed` calls on every deploy. On a second
 * run it re-asserts the Admin role and resets the password to whatever ADMIN_PASSWORD
 * currently says, which is also how you rotate it: change the variable, redeploy.
 */
class BootstrapAdmin extends Command
{
    protected $signature = 'operon:bootstrap-admin
                            {--name= : Display name for the account (default: "Admin")}';

    protected $description = 'Create or update the first Admin user from ADMIN_EMAIL / ADMIN_PASSWORD, and check MONEY_PIN';

    /** PINs that ship as examples. Fine locally, never in production. */
    private const PLACEHOLDER_PINS = ['1234', '0000', '1111', 'changeme'];

    public function handle(): int
    {
        $email = trim((string) config('operon.admin.email'));
        $password = (string) config('operon.admin.password');

        if ($email === '' || $password === '') {
            $this->components->error('ADMIN_EMAIL and ADMIN_PASSWORD must both be set. Nothing was created.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['email'],
                'password' => [Password::min(12)],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        if (! $this->moneyPinIsSafe()) {
            return self::FAILURE;
        }

        // The role has to exist before it can be granted; on a fresh database the roles
        // seeder runs first, but say so plainly rather than failing on a foreign key.
        if (! Role::where('name', 'Admin')->where('guard_name', 'web')->exists()) {
            $this->components->error('The Admin role does not exist yet. Run `php artisan db:seed --class=RolesAndPermissionsSeeder` first.');

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $this->option('name') ?: ($existing?->name ?? 'Admin'),
                'password' => Hash::make($password),
            ]
        );

        $user->syncRoles(['Admin']);

        $this->components->info(sprintf(
            '%s Admin %s.',
            $existing ? 'Updated' : 'Created',
            $email
        ));

        return self::SUCCESS;
    }

    /**
     * The PIN is the second lock on cost and margin. A production environment still
     * sitting on the example PIN is worse than no lock, because the screens claim to
     * be protected.
     */
    private function moneyPinIsSafe(): bool
    {
        $pin = (string) config('operon.money_pin');

        if ($pin === '') {
            $this->components->error('MONEY_PIN is empty. Set it before deploying - it guards every cost and margin screen.');

            return false;
        }

        if (! app()->environment('production')) {
            return true;
        }

        if (in_array($pin, self::PLACEHOLDER_PINS, true)) {
            $this->components->error('MONEY_PIN is still the example value. Set a real one - it guards every cost and margin screen.');

            return false;
        }

        return true;
    }
}
