@php($money = auth()->user()->canSeeMoney())

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Shipments — deliveries by ASN</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
                <div class="bg-green-50 border border-green-200 text-green-900 rounded-lg p-4 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <x-filter-bar
                :filters="$filters"
                :action="route('shipments.index')"
                :show="['dates', 'channels', 'fc', 'po', 'search', 'skus']"
                date-label="Delivery date" />

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-wrap gap-2">
                        @foreach([
                            '' => 'All deliveries',
                            'awaiting_final' => 'Booked, awaiting final',
                            'shipped' => 'Shipped',
                            'short' => 'Shipped short',
                        ] as $value => $label)
                            <a href="{{ route('shipments.index', $filters->query(array_filter(['stage' => $value ?: null]))) }}"
                               class="text-sm px-3 py-1.5 rounded-full border
                                      {{ ($stage ?? '') === $value ? 'bg-teal-700 text-white border-teal-700' : 'border-gray-300 text-gray-700 hover:bg-gray-50' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>

                    <a href="{{ route('shipments.index', $filters->query(array_filter(['export' => 'csv', 'stage' => $stage]))) }}"
                       class="text-sm px-4 py-2 border border-teal-700 text-teal-800 rounded-md hover:bg-teal-50">
                        Export CSV
                    </a>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-gray-500 border-b">
                        <tr>
                            <th class="py-2 pe-4">ASN</th>
                            <th class="py-2 pe-4">Reference</th>
                            <th class="py-2 pe-4">FC</th>
                            <th class="py-2 pe-4">Date</th>
                            <th class="py-2 pe-4 text-right">Booked</th>
                            <th class="py-2 pe-4 text-right">Shipped</th>
                            <th class="py-2 pe-4 text-right">Short</th>
                            @if($money)<th class="py-2 pe-4 text-right">Short AED</th>@endif
                            <th class="py-2">Stage</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($deliveries as $delivery)
                            <tr>
                                <td class="py-2 pe-4">
                                    <a href="{{ route('shipments.show', $delivery) }}"
                                       class="font-mono text-teal-800 underline">{{ $delivery->asn ?? $delivery->delivery_key }}</a>
                                </td>
                                <td class="py-2 pe-4">{{ $delivery->internal_ref ?? '—' }}</td>
                                <td class="py-2 pe-4">
                                    {{ $delivery->fc_code ?? '—' }}
                                    @if($delivery->has_fc_conflict)
                                        <span class="text-xs bg-amber-100 text-amber-900 px-2 py-0.5 rounded-full">mixed FC</span>
                                    @endif
                                </td>
                                <td class="py-2 pe-4">
                                    @if($delivery->delivered_on)
                                        {{ $delivery->delivered_on->format('d M Y') }}
                                        @if($delivery->delivery_date_is_manual)
                                            <span class="text-xs text-gray-500">(entered)</span>
                                        @endif
                                    @elseif($delivery->planned_date)
                                        {{ $delivery->planned_date->format('d M Y') }}
                                        <span class="text-xs text-gray-500">(planned)</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 pe-4 text-right">{{ number_format($delivery->units_interim) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($delivery->units_final) }}</td>
                                <td class="py-2 pe-4 text-right {{ $delivery->shortfall_units > 0 ? 'text-amber-800 font-semibold' : '' }}">
                                    {{ number_format($delivery->shortfall_units) }}
                                </td>
                                @if($money)
                                    <td class="py-2 pe-4 text-right">{{ number_format((float) $delivery->shortfall_value, 2) }}</td>
                                @endif
                                <td class="py-2">
                                    @if($delivery->has_final)
                                        <span class="text-xs bg-green-100 text-green-900 px-2 py-0.5 rounded-full">Shipped</span>
                                    @elseif($delivery->has_interim)
                                        <span class="text-xs bg-blue-100 text-blue-900 px-2 py-0.5 rounded-full">Awaiting final</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-6 text-center text-gray-500">No deliveries match these filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="mt-4">{{ $deliveries->links() }}</div>

                <p class="text-xs text-gray-500 mt-4">
                    Shortfall is what was booked onto the interim and did not make it onto the final,
                    so it only appears once both stages are in.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
