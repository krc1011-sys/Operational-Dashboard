<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Guards the blueprint §O permission rules, especially the two that are easy to
 * break by accident: uploads are Admin-only at launch, and Warehouse never sees money.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(bool $uploadsAdminOnly): void
    {
        config(['operon.uploads_admin_only' => $uploadsAdminOnly]);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWithRole(string $role): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    public function test_all_uploads_are_admin_only_at_launch(): void
    {
        $this->seedRoles(uploadsAdminOnly: true);

        foreach (['Finance', 'Sales', 'Procurement', 'Warehouse'] as $role) {
            $user = $this->userWithRole($role);

            foreach (RolesAndPermissionsSeeder::PERMISSIONS['uploads'] as $permission) {
                $this->assertFalse(
                    $user->can($permission),
                    "$role must NOT have $permission while launch lockdown is on."
                );
            }
        }

        $admin = $this->userWithRole('Admin');
        foreach (RolesAndPermissionsSeeder::PERMISSIONS['uploads'] as $permission) {
            $this->assertTrue($admin->can($permission), "Admin must have $permission.");
        }
    }

    public function test_full_matrix_restores_team_upload_rights_when_lockdown_is_off(): void
    {
        $this->seedRoles(uploadsAdminOnly: false);

        $this->assertTrue($this->userWithRole('Procurement')->can('upload-po'));
        $this->assertTrue($this->userWithRole('Warehouse')->can('upload-packing-list'));

        // Still Admin-only even with the lockdown off (§O).
        $this->assertFalse($this->userWithRole('Procurement')->can('upload-cancelled-items'));
        $this->assertFalse($this->userWithRole('Warehouse')->can('upload-master-sku'));
    }

    public function test_money_visibility_follows_the_matrix(): void
    {
        $this->seedRoles(uploadsAdminOnly: true);

        $expected = [
            'Admin' => ['view-margin' => true, 'view-sku-cost' => true, 'view-sku-price' => true],
            'Finance' => ['view-margin' => true, 'view-sku-cost' => true, 'view-sku-price' => true],
            'Sales' => ['view-margin' => false, 'view-sku-cost' => false, 'view-sku-price' => true],
            'Procurement' => ['view-margin' => false, 'view-sku-cost' => true, 'view-sku-price' => false],
            'Warehouse' => ['view-margin' => false, 'view-sku-cost' => false, 'view-sku-price' => false],
        ];

        foreach ($expected as $role => $permissions) {
            $user = $this->userWithRole($role);
            foreach ($permissions as $permission => $allowed) {
                $this->assertSame($allowed, $user->can($permission), "$role / $permission");
            }
        }
    }

    public function test_only_admin_manages_users_and_the_master_grid(): void
    {
        $this->seedRoles(uploadsAdminOnly: true);

        $this->assertTrue($this->userWithRole('Admin')->can('manage-users'));
        $this->assertTrue($this->userWithRole('Admin')->can('manage-master'));

        foreach (['Finance', 'Sales', 'Procurement', 'Warehouse'] as $role) {
            $user = $this->userWithRole($role);
            $this->assertFalse($user->can('manage-users'), "$role must not manage users.");
            $this->assertFalse($user->can('manage-master'), "$role must not edit the master grid.");
        }
    }
}
