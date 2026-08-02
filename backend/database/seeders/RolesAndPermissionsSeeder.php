<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the 5 roles (Admin, Finance, Sales, Procurement, Warehouse) and the permission
 * matrix drafted with the business owner. This matrix is a FIRST DRAFT pending final
 * sign-off - see DOCUMENTATION.md / chat history for the open questions (e.g. whether
 * Procurement should see sell price/margin). Adjust the arrays below once confirmed.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'view-overview',
            'view-po-status', 'manage-po-status',
            'view-fulfillment', 'manage-fulfillment',
            'view-pending',
            'view-analytics',           // SKU analytics / forecast / ABC-XYZ
            'view-margin',              // Margin/COGS tab
            'view-sku-cost',            // Master SKU buy price / unit cost
            'view-sku-price',           // Master SKU sell price
            'view-cancelled-items',
            'upload-po',
            'upload-packing-list',
            'upload-cancelled-items',
            'upload-master-sku',
            'manage-delivery-batches',  // warehouse batch-tracking feature (planned)
            'manage-email-assistant',   // Amazon email drafting feature (planned)
            'manage-users',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $admin->syncPermissions(Permission::all());

        $finance = Role::firstOrCreate(['name' => 'Finance']);
        $finance->syncPermissions([
            'view-overview', 'view-po-status', 'view-fulfillment', 'view-pending',
            'view-analytics', 'view-margin', 'view-sku-cost', 'view-sku-price',
            'view-cancelled-items',
        ]);

        $sales = Role::firstOrCreate(['name' => 'Sales']);
        $sales->syncPermissions([
            'view-overview', 'view-po-status', 'view-analytics', 'view-sku-price',
        ]);

        $procurement = Role::firstOrCreate(['name' => 'Procurement']);
        $procurement->syncPermissions([
            'view-overview', 'view-po-status', 'manage-po-status',
            'view-fulfillment', 'manage-fulfillment', 'view-pending', 'view-analytics',
            'view-sku-cost', 'view-cancelled-items',
            'upload-po', 'manage-delivery-batches', 'manage-email-assistant',
        ]);

        $warehouse = Role::firstOrCreate(['name' => 'Warehouse']);
        $warehouse->syncPermissions([
            'view-overview', 'view-po-status', 'view-fulfillment', 'manage-fulfillment',
            'view-pending', 'view-cancelled-items',
            'upload-packing-list', 'manage-delivery-batches',
        ]);
    }
}
