<x-operon-page title="Dashboard">
    

    <div class="op-legacy">
        <div style="display:flex;flex-direction:column;gap:16px">

            <x-upload-freshness :overdue="$overdue ?? []" />

            @if(($awaitingDecision ?? 0) > 0)
                <div class="bg-amber-50 border border-amber-300 rounded-lg p-4 text-sm text-amber-900">
                    <strong>{{ $awaitingDecision }}</strong>
                    cancellation{{ $awaitingDecision === 1 ? '' : 's' }} for units already booked or shipped
                    {{ $awaitingDecision === 1 ? 'is' : 'are' }} waiting on you. Nothing has been netted off
                    until you answer.
                    <a href="{{ route('cancellations.index') }}" class="underline font-medium">Deliver anyway, or pull it →</a>
                </div>
            @endif

            <div class="overflow-hidden shadow-sm sm:rounded-lg p-6">
                <p class="text-lg">Welcome, <strong>{{ auth()->user()->name }}</strong></p>
                <p style="color:var(--muted)">{{ auth()->user()->email }}</p>
                <p class="mt-2">
                    Role:
                    @forelse(auth()->user()->getRoleNames() as $role)
                        <span class="inline-block bg-teal-100 text-sm px-3 py-1 rounded-full font-medium" style="color:var(--teal-2)">{{ $role }}</span>
                    @empty
                        <span class="text-red-600">No role assigned</span>
                    @endforelse
                </p>
            </div>

            @php
                /*
                 * The permission matrix, live. The screens that exist are links; the ones
                 * still to be built show as ticks so it stays obvious what a role will get.
                 * Nothing here is hard-coded per role - it all reads from the seeder.
                 */
                $screenRoutes = [
                    'view-overview' => 'overview.index',
                    'view-po-status' => 'po-lookup.index',
                    'view-fulfillment' => 'fulfilment.index',
                    'view-pending' => 'pending.index',
                    'view-shipments' => 'shipments.index',
                    'view-committed-deliveries' => 'committed.index',
                    'view-cancelled-items' => 'cancellations.index',
                ];

                $groups = [
                    'Screens' => [
                        'Overview' => 'view-overview',
                        'PO Lookup' => 'view-po-status',
                        'Fulfilment / Fill rate' => 'view-fulfillment',
                        'Pending (not yet booked)' => 'view-pending',
                        'Shipments (by ASN)' => 'view-shipments',
                        'Committed deliveries' => 'view-committed-deliveries',
                        'Cancelled items' => 'view-cancelled-items',
                        'SKU analytics' => 'view-analytics',
                        'Sell-out' => 'view-sellout',
                        'DFS orders' => 'view-dfs',
                        'Master catalog' => 'view-master',
                    ],
                    'Money (also needs the PIN)' => [
                        'Margin / net P&L' => 'view-margin',
                        'Buy price (cost)' => 'view-sku-cost',
                        'Sell price (RSP)' => 'view-sku-price',
                    ],
                    'Uploads' => [
                        'Amazon PO' => 'upload-po',
                        'Amazon packing lists' => 'upload-packing-list',
                        'Amazon cancellations' => 'upload-cancelled-items',
                        'Master sheet' => 'upload-master-sku',
                        'Sell-out report' => 'upload-sellout',
                        'DFS orders' => 'upload-dfs',
                        'Noon PO' => 'upload-noon-po',
                        'Noon picking lists' => 'upload-noon-picking-list',
                    ],
                    'Actions' => [
                        'Edit PO status' => 'manage-po-status',
                        'Edit fulfilment' => 'manage-fulfillment',
                        'Decide cancellations' => 'decide-cancellations',
                        'Edit master grid' => 'manage-master',
                        'Delivery batches' => 'manage-delivery-batches',
                        'Email assistant' => 'manage-email-assistant',
                        'Manage users' => 'manage-users',
                    ],
                ];
            @endphp

            @foreach($groups as $groupLabel => $modules)
                <div class="overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold text-lg mb-4">{{ $groupLabel }}</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        @foreach($modules as $label => $perm)
                            @php
                                $allowed = auth()->user()->can($perm);
                                $route = $allowed ? ($screenRoutes[$perm] ?? null) : null;
                            @endphp
                            <{{ $route ? 'a' : 'div' }} @if($route) href="{{ route($route) }}" @endif
                                class="flex items-center gap-2 p-3 rounded border {{ $allowed ? 'border-green-300 bg-green-50' : 'border-gray-200 opacity-50' }} {{ $route ? 'hover:bg-green-100 transition' : '' }}">
                                <span>{{ $allowed ? '✅' : '⚪' }}</span>
                                <span class="text-sm {{ $route ? 'underline' : '' }}">{{ $label }}</span>
                            </{{ $route ? 'a' : 'div' }}>
                        @endforeach
                    </div>

                    @if($groupLabel === 'Uploads' && config('operon.uploads_admin_only'))
                        <p class="text-xs text-amber-700 note warn p-3 mt-4">
                            <strong>Launch mode:</strong> all uploads are Admin-only. The full matrix
                            (Procurement uploads POs, Warehouse uploads packing lists) is already built —
                            set <code>OPERON_UPLOADS_ADMIN_ONLY=false</code> and re-seed to switch it on.
                        </p>
                    @endif

                    @if($groupLabel === 'Money (also needs the PIN)')
                        <p class="text-xs mt-4" style="color:var(--muted)">
                            @if(\App\Http\Middleware\EnsureMoneyPinVerified::verified(request()))
                                🔓 PIN confirmed for this session.
                                <a href="{{ route('money-pin.lock') }}"
                                   onclick="event.preventDefault(); document.getElementById('lock-money').submit();"
                                   class="underline">Lock again</a>
                                <form id="lock-money" method="POST" action="{{ route('money-pin.lock') }}" class="hidden">@csrf</form>
                            @else
                                🔒 Money screens are locked until you enter the PIN.
                            @endif
                        </p>
                    @endif
                </div>
            @endforeach

            <p class="text-xs" style="color:var(--faint)">
                This grid is generated live from the permission matrix in
                <code>RolesAndPermissionsSeeder.php</code> — change the seeder, re-run it, and this
                view updates. No hard-coded role logic anywhere. The ticked screens that are not yet
                links are the ones still to be built.
            </p>

        </div>
    </div>
</x-operon-page>
