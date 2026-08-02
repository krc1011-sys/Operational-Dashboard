<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Operon
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <p class="text-lg">Welcome, <strong>{{ auth()->user()->name }}</strong></p>
                <p class="text-gray-600">{{ auth()->user()->email }}</p>
                <p class="mt-2">
                    Role:
                    @forelse(auth()->user()->getRoleNames() as $role)
                        <span class="inline-block bg-indigo-100 text-indigo-800 text-sm px-3 py-1 rounded-full font-medium">{{ $role }}</span>
                    @empty
                        <span class="text-red-600">No role assigned</span>
                    @endforelse
                </p>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-lg mb-4">Modules visible to you (live from the RBAC permission matrix)</h3>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    @php
                        $modules = [
                            'Overview' => 'view-overview',
                            'PO Status' => 'view-po-status',
                            'Fulfillment' => 'view-fulfillment',
                            'Pending' => 'view-pending',
                            'SKU Analytics / Forecast' => 'view-analytics',
                            'Margin / COGS' => 'view-margin',
                            'Master SKU - cost' => 'view-sku-cost',
                            'Master SKU - price' => 'view-sku-price',
                            'Cancelled Items' => 'view-cancelled-items',
                            'Upload PO' => 'upload-po',
                            'Upload Packing List' => 'upload-packing-list',
                            'Manage Users' => 'manage-users',
                        ];
                    @endphp
                    @foreach($modules as $label => $perm)
                        <div class="flex items-center gap-2 p-3 rounded border {{ auth()->user()->can($perm) ? 'border-green-300 bg-green-50' : 'border-gray-200 bg-gray-50 opacity-50' }}">
                            <span>{{ auth()->user()->can($perm) ? '✅' : '⚪' }}</span>
                            <span class="text-sm">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500 mt-4">This grid is generated directly from the permission matrix seeded in RolesAndPermissionsSeeder.php - change the seeder, re-run it, and this view updates automatically. No hard-coded role logic.</p>
            </div>

        </div>
    </div>
</x-app-layout>
