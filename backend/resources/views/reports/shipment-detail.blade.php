@php($money = auth()->user()->canSeeMoney())

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('shipments.index') }}" class="text-sm text-teal-800 underline">← All deliveries</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight font-mono">
                ASN {{ $delivery->asn ?? $delivery->delivery_key }}
            </h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
                <div class="bg-green-50 border border-green-200 text-green-900 rounded-lg p-4 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 text-sm">
                    @foreach([
                        'Reference' => $delivery->internal_ref ?? '—',
                        'Fulfilment centre' => $delivery->fc_code ?? 'not derivable yet',
                        'Booked units' => number_format($delivery->units_interim),
                        'Shipped units' => number_format($delivery->units_final),
                        'Shortfall' => number_format($delivery->shortfall_units) . ' units',
                        'POs in this delivery' => count($poNumbers),
                    ] as $label => $value)
                        <div>
                            <div class="text-xs text-gray-500">{{ $label }}</div>
                            <div class="font-medium">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($poNumbers as $poNumber)
                        <a href="{{ route('po-lookup.show', $poNumber) }}"
                           class="font-mono text-xs px-2 py-1 rounded bg-gray-100 text-teal-800 underline">{{ $poNumber }}</a>
                    @endforeach
                </div>

                @if($money && ($delivery->value_final > 0 || $delivery->value_interim > 0))
                    <p class="text-sm text-gray-600 mt-4">
                        Booked value {{ number_format((float) $delivery->value_interim, 2) }} AED ·
                        invoiced {{ number_format((float) $delivery->value_final, 2) }} AED ·
                        shortfall <strong>{{ number_format((float) $delivery->shortfall_value, 2) }} AED</strong>
                    </p>
                @endif
            </div>

            {{-- The delivery date drives the PO's turnaround, so it is correctable (§L, §Q). --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold">Delivery date</h3>
                <p class="text-sm text-gray-600 mt-1">
                    @if($delivery->delivered_on)
                        Currently <strong>{{ $delivery->delivered_on->format('d M Y') }}</strong>{{ $delivery->delivery_date_is_manual ? ' — entered by hand' : ' — read from the final packing list' }}.
                    @elseif($delivery->planned_date)
                        Only a planned date is known ({{ $delivery->planned_date->format('d M Y') }}), and planned
                        dates get rescheduled, so nothing is measured from it.
                    @else
                        No date is known for this delivery.
                    @endif
                    Turnaround is measured to this date, so correcting it recalculates every PO in the delivery.
                </p>

                @can('manage-fulfillment')
                    <form method="POST" action="{{ route('shipments.date', $delivery) }}" class="mt-3 flex flex-wrap items-end gap-3">
                        @csrf
                        @method('PATCH')
                        <div>
                            <x-input-label for="delivered_on" value="Actual delivery date" />
                            <x-text-input id="delivered_on" name="delivered_on" type="date" class="mt-1"
                                          :value="$delivery->delivered_on?->toDateString() ?? $delivery->planned_date?->toDateString()"
                                          required />
                            <x-input-error :messages="$errors->get('delivered_on')" class="mt-2" />
                        </div>
                        <x-primary-button>Save date</x-primary-button>
                    </form>
                @endcan
            </div>

            {{-- §L: shortfall attributable to a specific SKU, not just a delivery total. --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <h3 class="font-semibold text-lg mb-1">What was booked, and what shipped</h3>
                <p class="text-sm text-gray-600 mb-4">
                    Per SKU, so a shortfall points at the product that caused it.
                </p>

                <table class="min-w-full text-sm">
                    <thead class="text-left text-gray-500 border-b">
                        <tr>
                            <th class="py-2 pe-4">PO</th>
                            <th class="py-2 pe-4">ASIN / NIN</th>
                            <th class="py-2 pe-4 text-right">Booked</th>
                            <th class="py-2 pe-4 text-right">Shipped</th>
                            <th class="py-2 pe-4 text-right">Short</th>
                            @if($money)<th class="py-2 text-right">Short AED</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($rows as $row)
                            @php($short = max(0, (int) $row->interim - (int) $row->final_qty))
                            <tr>
                                <td class="py-2 pe-4">
                                    <a href="{{ route('po-lookup.show', $row->po_number) }}"
                                       class="font-mono text-teal-800 underline">{{ $row->po_number }}</a>
                                </td>
                                <td class="py-2 pe-4">
                                    <span class="font-mono">{{ $row->sku_id }}</span>
                                    <div class="text-xs text-gray-500">{{ Str::limit($row->title, 60) }}</div>
                                </td>
                                <td class="py-2 pe-4 text-right">{{ number_format((int) $row->interim) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format((int) $row->final_qty) }}</td>
                                <td class="py-2 pe-4 text-right {{ $short > 0 && $delivery->has_final ? 'text-amber-800 font-semibold' : '' }}">
                                    {{ $delivery->has_final ? number_format($short) : '—' }}
                                </td>
                                @if($money)
                                    <td class="py-2 text-right">
                                        {{ $delivery->has_final ? number_format($short * (float) $row->unit_cost, 2) : '—' }}
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @unless($delivery->has_final)
                    <p class="text-xs text-gray-500 mt-4">
                        This delivery has no final packing list yet, so nothing here has shipped and there is
                        no shortfall to show.
                    </p>
                @endunless
            </div>

        </div>
    </div>
</x-app-layout>
