<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Upcoming committed deliveries</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-teal-50 border border-teal-200 rounded-lg p-4 text-sm text-teal-900">
                <strong>What this answers:</strong> how many units of each ASIN are already booked to
                ship on a delivery that has not gone yet. Check a DFS order against this before placing
                it, or you order stock that a PO is about to ship anyway.
                <span class="block mt-1 text-teal-800">
                    Paste the ASINs from the DFS order into the filter below to see just those.
                </span>
            </div>

            <x-filter-bar
                :filters="$filters"
                :action="route('committed.index')"
                :show="['dates', 'channels', 'fc', 'search', 'skus']"
                date-label="Delivery date" />

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex gap-8">
                        <div>
                            <div class="text-xs text-gray-500">Units committed</div>
                            <div class="text-2xl font-semibold">{{ number_format($totalUnits) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">Across SKUs</div>
                            <div class="text-2xl font-semibold">{{ number_format($rows->count()) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">On deliveries</div>
                            <div class="text-2xl font-semibold">{{ number_format($deliveryCount) }}</div>
                        </div>
                    </div>

                    <a href="{{ route('committed.index', $filters->query(['export' => 'csv'])) }}"
                       class="text-sm px-4 py-2 border border-teal-700 text-teal-800 rounded-md hover:bg-teal-50">
                        Export CSV
                    </a>
                </div>
            </div>

            @if($filters->skus !== [])
                @php($found = $rows->pluck('sku_id')->all())
                @php($missing = array_values(array_diff($filters->skus, $found)))

                @if($missing !== [])
                    <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm">
                        <strong>{{ count($missing) }}</strong> of the
                        {{ count($filters->skus) }} identifier(s) you pasted have
                        <strong>nothing</strong> committed — order those freely as far as this screen is
                        concerned.
                        <details class="mt-2">
                            <summary class="cursor-pointer text-teal-800">Show them</summary>
                            <p class="font-mono text-xs mt-2 break-all">{{ implode(', ', array_slice($missing, 0, 200)) }}</p>
                        </details>
                    </div>
                @endif
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-gray-500 border-b">
                        <tr>
                            <th class="py-2 pe-4">ASIN / NIN</th>
                            <th class="py-2 pe-4 text-right">Units committed</th>
                            <th class="py-2 pe-4 text-right">Deliveries</th>
                            <th class="py-2 pe-4 text-right">POs</th>
                            <th class="py-2 pe-4">Next delivery</th>
                            <th class="py-2">FC</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($rows as $row)
                            <tr>
                                <td class="py-2 pe-4">
                                    <span class="font-mono">{{ $row['sku_id'] }}</span>
                                    <div class="text-xs text-gray-500">{{ Str::limit($row['title'], 60) }}</div>
                                </td>
                                <td class="py-2 pe-4 text-right font-semibold">{{ number_format($row['units']) }}</td>
                                <td class="py-2 pe-4 text-right">{{ $row['delivery_count'] }}</td>
                                <td class="py-2 pe-4 text-right">{{ $row['po_count'] }}</td>
                                <td class="py-2 pe-4">
                                    {{ $row['next_date']?->format('d M Y') ?? 'not scheduled' }}
                                    @if($row['next_asn'])
                                        <div class="text-xs text-gray-500 font-mono">{{ $row['next_asn'] }}</div>
                                    @endif
                                </td>
                                <td class="py-2">{{ $row['next_fc'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-gray-500">
                                    Nothing is committed on an unshipped delivery for these filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <p class="text-xs text-gray-500 mt-4">
                    "Committed" means booked onto an interim packing list for a delivery with no final
                    yet. Once a delivery ships, its units drop off this screen — they are gone, not
                    something to net a new order against. Planned dates come from the packing list and
                    Amazon does reschedule them.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
