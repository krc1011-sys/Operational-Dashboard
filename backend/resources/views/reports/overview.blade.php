@php
    use App\Services\Reporting\FulfilmentQuery;

    $showValue = auth()->user()->canSeeOrderValue();
    $fillStatus = FulfilmentQuery::rate($totals['fill_rate'], $benchmarks['fill_rate_target']);
    $confirmStatus = FulfilmentQuery::rate($totals['confirmation_rate'], $benchmarks['confirmation_rate_target']);
    $turnaroundStatus = $averageDays === null
        ? 'neutral'
        : ($averageDays <= $benchmarks['turnaround_days'] ? 'good' : 'bad');
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Overview</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <x-filter-bar :filters="$filters" :action="route('overview.index')" />

            {{-- Row 1: how we are performing, against Amazon's own targets (§M). --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Performance</h3>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <x-kpi-tile
                        label="Fill rate (shipped ÷ net accepted)"
                        :value="$totals['fill_rate'] === null ? '—' : $totals['fill_rate'] . '%'"
                        :sub="number_format($totals['shipped']) . ' of ' . number_format($totals['net_accepted']) . ' units'"
                        :target="$benchmarks['fill_rate_target'] . '%'"
                        :status="$fillStatus"
                        :href="route('fulfilment.index', $filters->query())" />

                    <x-kpi-tile
                        label="PO confirmation rate (accepted ÷ requested)"
                        :value="$totals['confirmation_rate'] === null ? '—' : $totals['confirmation_rate'] . '%'"
                        :sub="number_format($totals['accepted']) . ' of ' . number_format($totals['requested']) . ' requested'"
                        :target="$benchmarks['confirmation_rate_target'] . '%'"
                        :status="$confirmStatus" />

                    <x-kpi-tile
                        label="Average turnaround"
                        :value="$averageDays === null ? '—' : $averageDays . ' days'"
                        :sub="$completedCount . ' completed PO(s) measured'"
                        :target="$benchmarks['turnaround_days'] . ' days'"
                        :status="$turnaroundStatus"
                        :href="route('po-lookup.index', $filters->query(['po_status' => 'complete']))" />

                    <x-kpi-tile
                        label="Open POs past the benchmark"
                        :value="number_format($lateCount)"
                        :sub="number_format($openCount) . ' open in total'"
                        :status="$lateCount > 0 ? 'bad' : 'good'"
                        :href="route('po-lookup.index', $filters->query(['po_status' => 'late']))" />
                </div>
            </div>

            {{-- Row 2: what is actually happening right now. --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Operations</h3>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <x-kpi-tile
                        label="Units not yet booked"
                        :value="number_format($totals['not_booked'])"
                        sub="Accepted, but on no delivery yet"
                        :status="$totals['not_booked'] > 0 ? 'warn' : 'good'"
                        :href="route('pending.index', $filters->query())" />

                    <x-kpi-tile
                        label="Shortfall"
                        :value="number_format($totals['shortfall_units']) . ' units'"
                        :sub="$showValue ? number_format($totals['shortfall_value'], 2) . ' AED' : 'Accepted but not shipped'"
                        :status="$totals['shortfall_units'] > 0 ? 'warn' : 'good'"
                        :href="route('fulfilment.index', $filters->query())" />

                    <x-kpi-tile
                        label="Deliveries awaiting their final"
                        :value="number_format($awaitingFinal)"
                        sub="Booked, not yet shipped"
                        :href="route('shipments.index', ['stage' => 'awaiting_final'])" />

                    <x-kpi-tile
                        label="Chargeback exposure"
                        :value="number_format($chargebackUnits) . ' units'"
                        :sub="$needsDecision > 0 ? $needsDecision . ' cancellation(s) awaiting a decision' : 'Shipped against a cancellation'"
                        :status="$needsDecision > 0 ? 'bad' : ($chargebackUnits > 0 ? 'warn' : 'good')"
                        :href="route('cancellations.index')" />
                </div>
            </div>

            {{-- The raw numbers behind the tiles, for anyone who wants them. --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-lg mb-4">The numbers behind these tiles</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    @foreach([
                        'Purchase orders' => $totals['po_count'],
                        'PO lines' => $totals['line_count'],
                        'Distinct SKUs' => $totals['sku_count'],
                        'Units requested' => $totals['requested'],
                        'Units accepted' => $totals['accepted'],
                        'Units cancelled (honoured)' => $totals['cancelled'],
                        'Net accepted' => $totals['net_accepted'],
                        'Units booked' => $totals['booked'],
                        'Units shipped' => $totals['shipped'],
                        'Units not booked' => $totals['not_booked'],
                    ] as $label => $value)
                        <div class="border rounded p-3">
                            <div class="text-xs text-gray-500">{{ $label }}</div>
                            <div class="font-semibold">{{ number_format($value) }}</div>
                        </div>
                    @endforeach

                    @if($showValue)
                        <div class="border rounded p-3">
                            <div class="text-xs text-gray-500">Shipped value</div>
                            <div class="font-semibold">{{ number_format($totals['shipped_value'], 2) }} AED</div>
                        </div>
                        <div class="border rounded p-3">
                            <div class="text-xs text-gray-500">Booked value</div>
                            <div class="font-semibold">{{ number_format($totals['booked_value'], 2) }} AED</div>
                        </div>
                    @endif
                </div>

                <p class="text-xs text-gray-500 mt-4">
                    Every figure here is the engine's own — the same cached columns the
                    Fulfilment and PO screens read, narrowed by the filters above. Margin and
                    profitability are not on this screen: those are Admin-only and behind the PIN.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
