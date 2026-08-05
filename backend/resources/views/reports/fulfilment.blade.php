@php
    use App\Services\Reporting\FilterSet;

    $showValue = auth()->user()->canSeeOrderValue();
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Fulfilment — fill rate and shortfall</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <x-filter-bar
                :filters="$filters"
                :action="route('fulfilment.index')"
                :show="['dates', 'channels', 'fc', 'brand', 'category', 'status', 'po', 'search', 'group', 'skus']" />

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-wrap gap-6 text-sm">
                        <div>
                            <div class="text-xs text-gray-500">Fill rate</div>
                            <div class="text-xl font-semibold">
                                {{ $totals['fill_rate'] === null ? '—' : $totals['fill_rate'] . '%' }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">Shipped ÷ net accepted</div>
                            <div class="text-xl font-semibold">
                                {{ number_format($totals['shipped']) }} / {{ number_format($totals['net_accepted']) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">Shortfall</div>
                            <div class="text-xl font-semibold text-amber-800">
                                {{ number_format($totals['shortfall_units']) }} units
                                @if($showValue)
                                    <span class="text-sm font-normal text-gray-600">
                                        ({{ number_format($totals['shortfall_value'], 2) }} AED)
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <a href="{{ route('fulfilment.index', $filters->query(['export' => 'csv'])) }}"
                       class="text-sm px-4 py-2 border border-teal-700 text-teal-800 rounded-md hover:bg-teal-50">
                        Export CSV
                    </a>
                </div>
            </div>

            @if($grouped !== null)
                {{-- §M group-by: SKU, brand or category, worst shortfall first. --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                    <h3 class="font-semibold mb-4">Grouped by {{ $filters->groupBy }}</h3>
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-gray-500 border-b">
                            <tr>
                                <th class="py-2 pe-4">{{ ucfirst($filters->groupBy) }}</th>
                                <th class="py-2 pe-4 text-right">SKUs</th>
                                <th class="py-2 pe-4 text-right">Net accepted</th>
                                <th class="py-2 pe-4 text-right">Booked</th>
                                <th class="py-2 pe-4 text-right">Shipped</th>
                                <th class="py-2 pe-4 text-right">Fill %</th>
                                <th class="py-2 pe-4 text-right">Short</th>
                                @if($showValue)<th class="py-2 text-right">Short AED</th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($grouped as $row)
                                <tr>
                                    <td class="py-2 pe-4">
                                        <span class="font-mono">{{ $row['key'] }}</span>
                                        @if($filters->groupBy === FilterSet::GROUP_SKU && $row['title'])
                                            <div class="text-xs text-gray-500">{{ Str::limit($row['title'], 60) }}</div>
                                        @endif
                                    </td>
                                    <td class="py-2 pe-4 text-right">{{ number_format($row['sku_count']) }}</td>
                                    <td class="py-2 pe-4 text-right">{{ number_format($row['net_accepted']) }}</td>
                                    <td class="py-2 pe-4 text-right">{{ number_format($row['booked']) }}</td>
                                    <td class="py-2 pe-4 text-right">{{ number_format($row['shipped']) }}</td>
                                    <td class="py-2 pe-4 text-right">{{ $row['fill_rate'] === null ? '—' : $row['fill_rate'] . '%' }}</td>
                                    <td class="py-2 pe-4 text-right {{ $row['shortfall_units'] > 0 ? 'text-amber-800 font-semibold' : '' }}">
                                        {{ number_format($row['shortfall_units']) }}
                                    </td>
                                    @if($showValue)
                                        <td class="py-2 text-right">{{ number_format($row['shortfall_value'], 2) }}</td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="8" class="py-6 text-center text-gray-500">Nothing matches these filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-gray-500 border-b">
                            <tr>
                                <th class="py-2 pe-4">PO</th>
                                <th class="py-2 pe-4">ASIN / NIN</th>
                                <th class="py-2 pe-4">FC</th>
                                <th class="py-2 pe-4">Status</th>
                                <th class="py-2 pe-4 text-right">Accepted</th>
                                <th class="py-2 pe-4 text-right">Cancelled</th>
                                <th class="py-2 pe-4 text-right">Booked</th>
                                <th class="py-2 pe-4 text-right">Shipped</th>
                                <th class="py-2 pe-4 text-right">Fill %</th>
                                <th class="py-2 pe-4 text-right">Short</th>
                                @if($showValue)<th class="py-2 text-right">Short AED</th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($lines as $line)
                                @php($short = max(0, $line->qty_net_accepted - $line->qty_shipped))
                                <tr>
                                    <td class="py-2 pe-4">
                                        <a href="{{ route('po-lookup.show', $line->po_number) }}"
                                           class="font-mono text-teal-800 underline">{{ $line->po_number }}</a>
                                    </td>
                                    <td class="py-2 pe-4">
                                        <span class="font-mono">{{ $line->sku_id }}</span>
                                        <div class="text-xs text-gray-500">{{ Str::limit($line->title, 50) }}</div>
                                    </td>
                                    <td class="py-2 pe-4">{{ $line->ship_to_fc }}</td>
                                    <td class="py-2 pe-4">
                                        {{ \App\Services\Reporting\FilterSet::lineStates()[$line->line_state] ?? $line->line_state }}
                                        @if($line->has_chargeback_flag)
                                            <span class="ms-1 text-xs bg-red-100 text-red-800 px-2 py-0.5 rounded-full">chargeback</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pe-4 text-right">{{ number_format($line->qty_accepted) }}</td>
                                    <td class="py-2 pe-4 text-right">{{ number_format($line->qty_cancelled_honoured) }}</td>
                                    <td class="py-2 pe-4 text-right">{{ number_format($line->qty_booked) }}</td>
                                    <td class="py-2 pe-4 text-right">{{ number_format($line->qty_shipped) }}</td>
                                    <td class="py-2 pe-4 text-right">
                                        {{ $line->fill_rate_pct === null ? '—' : round((float) $line->fill_rate_pct, 1) . '%' }}
                                    </td>
                                    <td class="py-2 pe-4 text-right {{ $short > 0 ? 'text-amber-800 font-semibold' : '' }}">
                                        {{ number_format($short) }}
                                    </td>
                                    @if($showValue)
                                        <td class="py-2 text-right">
                                            {{ number_format($short * (float) $line->unit_cost, 2) }}
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="11" class="py-6 text-center text-gray-500">Nothing matches these filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>

                    <div class="mt-4">{{ $lines->links() }}</div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
