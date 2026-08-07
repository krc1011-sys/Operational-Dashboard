<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the 5 roles and the OperON permission matrix.
 *
 * Source of truth: 03_LOGIC_BLUEPRINT.md §O (Roles & permissions) and §T (file registry).
 *
 * Two things to know before editing:
 *
 *  1. THE FULL MATRIX below is the long-term target (Procurement uploads POs,
 *     Warehouse uploads packing lists, etc).
 *  2. THE LAUNCH OVERRIDE (config `operon.uploads_admin_only`, default true) strips
 *     every `upload-*` permission from every non-Admin role at seed time, because
 *     the current-phase rule is ALL UPLOADS ADMIN-ONLY. Set
 *     OPERON_UPLOADS_ADMIN_ONLY=false in .env and re-seed to hand upload rights
 *     to the team once they are onboarded.
 *
 * This seeder is idempotent — safe to run repeatedly. It syncs, so removing a
 * permission from an array below genuinely revokes it on the next run.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Every permission in the system, grouped for readability.
     * The group keys are documentation only; the flat names are what get stored.
     */
    public const PERMISSIONS = [

        // --- Tabs / screens (view) ------------------------------------------
        'screens' => [
            'view-overview',                // Overview KPI tiles (§M)
            'view-po-status',               // PO Lookup, incl. multi-ISA drill-down (§L)
            'view-fulfillment',             // Fill rate / shortfall engine output (§E, §L)
            'view-pending',                 // Not-yet-booked units (§F)
            'view-shipments',               // Deliveries by ASN, interim vs final (§K)
            'view-committed-deliveries',    // "Upcoming committed deliveries" lookup (§R)
            'view-cancelled-items',         // Cancellations + deliver-anyway flags (§G)
            'view-analytics',               // SKU analytics / forecast (Phase 2-3)
            'view-sellout',                 // Amazon sell-out / sell-through (§P)
            'view-inventory',               // Stock on hand, velocity, days of cover (M9)
            'view-dfs',                     // Amazon DFS orders (§R)
            'view-master',                  // Master product catalog, NON-money columns (§S)
        ],

        // --- Actions (manage) -----------------------------------------------
        'actions' => [
            'manage-po-status',
            'manage-fulfillment',
            /*
             * Answering "deliver anyway or pull it" on a cancellation (§G).
             *
             * ADMIN ONLY for now, deliberately: the eventual owner has not been decided.
             * It is its own permission rather than part of `manage-fulfillment` so that
             * handing it to Procurement or Warehouse later is one line in the matrix
             * below, with nothing else moving. The decision commits us to shipping (or
             * not shipping) against Amazon's cancellation and carries chargeback risk,
             * so it stays with Admin until that owner is named.
             */
            'decide-cancellations',
            'manage-master',                // Edit the in-app master grid (§S — Admin + PIN)
            'manage-delivery-batches',      // Warehouse batch tracking (planned)
            'manage-email-assistant',       // Amazon email drafting (planned)
            'manage-users',
        ],

        // --- Money visibility (§O) — additionally PIN-gated in the UI (§S) ---
        'money' => [
            'view-margin',                  // Margin / net P&L        → Admin + Finance
            'view-sku-cost',                // Buy price / unit cost   → Admin + Finance + Procurement
            'view-sku-price',               // Sell price / RSP        → Admin + Finance + Sales
        ],

        // --- Uploads, one per file type in the §T registry -------------------
        'uploads' => [
            'upload-po',                    // Amazon PO: bulk .xls + single .xlsx
            'upload-packing-list',          // Amazon interim + final packing lists
            'upload-cancelled-items',       // Amazon cancellations template
            'upload-master-sku',            // Master Products Sheet bulk load
            'upload-sellout',               // Amazon sell-out report
            'upload-dfs',                   // Amazon DFS orders
            'upload-inventory',             // Amazon + DFS stock on hand (M9)
            'upload-noon-po',               // Noon PO ("Packing List" tab)
            'upload-noon-picking-list',     // Noon interim + final ("Picking List" tab)
            'upload-noon-sellout',          // Noon sell-out + SOH workbook (M9)
        ],
    ];

    /**
     * THE FULL MATRIX (long-term target, §O).
     * Admin is not listed — Admin always receives every permission.
     */
    public const MATRIX = [

        'Finance' => [
            'view-overview', 'view-po-status', 'view-fulfillment', 'view-pending',
            'view-shipments', 'view-committed-deliveries', 'view-cancelled-items',
            'view-analytics', 'view-sellout', 'view-inventory', 'view-dfs', 'view-master',
            // Finance sees all three money lenses.
            'view-margin', 'view-sku-cost', 'view-sku-price',
        ],

        'Sales' => [
            'view-overview', 'view-po-status', 'view-pending',
            'view-committed-deliveries', 'view-analytics', 'view-sellout', 'view-inventory',
            'view-dfs', 'view-master',
            // Sell price only — no cost, no margin.
            'view-sku-price',
        ],

        'Procurement' => [
            'view-overview', 'view-po-status', 'manage-po-status',
            'view-fulfillment', 'manage-fulfillment', 'view-pending',
            'view-shipments', 'view-committed-deliveries', 'view-cancelled-items',
            'view-analytics', 'view-sellout', 'view-inventory', 'view-dfs', 'view-master',
            // Buy price only — no sell price, no margin.
            'view-sku-cost',
            'manage-delivery-batches', 'manage-email-assistant',
            // Uploads (revoked at launch by the override below).
            'upload-po', 'upload-noon-po',
        ],

        'Warehouse' => [
            'view-overview', 'view-po-status', 'view-fulfillment', 'manage-fulfillment',
            'view-pending', 'view-shipments', 'view-cancelled-items', 'view-master',
            // Stock on hand and days of cover: the warehouse's own question (M9).
            'view-inventory',
            // None of the three §O money lenses: no buy price, no sell price, no margin.
            // Order value (units x unit cost) is not one of those lenses and is open to
            // every role - see User::canSeeOrderValue().
            'manage-delivery-batches',
            // Uploads (revoked at launch by the override below).
            'upload-packing-list', 'upload-noon-picking-list',
        ],
    ];

    public function run(): void
    {
        // 1. Create every permission.
        foreach ($this->allPermissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // 2. Admin gets everything, always.
        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin->syncPermissions($this->allPermissions());

        // 3. Every other role gets its matrix row, minus uploads if locked down.
        $lockdown = (bool) config('operon.uploads_admin_only');

        foreach (self::MATRIX as $roleName => $granted) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            if ($lockdown) {
                $granted = array_values(array_filter(
                    $granted,
                    fn (string $p) => ! Str::startsWith($p, 'upload-')
                ));
            }

            $role->syncPermissions($granted);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info($lockdown
            ? 'Roles seeded. LAUNCH MODE: all uploads are Admin-only (operon.uploads_admin_only=true).'
            : 'Roles seeded. FULL MATRIX: Procurement and Warehouse have their upload rights.');
    }

    /** Flat list of every permission name. */
    private function allPermissions(): array
    {
        return array_merge(...array_values(self::PERMISSIONS));
    }
}
