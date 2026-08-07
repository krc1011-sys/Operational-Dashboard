@php($showValue = auth()->user()->canSeeOrderValue())

<x-operon-page title="Delivery {{ $delivery->asn ?? $delivery->delivery_key }}">
    

    <div class="op-legacy">
        <div style="display:flex;flex-direction:column;gap:16px">

            @if(session('status'))
                <div class="bg-green-50 border border-green-200 text-green-900 rounded-lg p-4 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="panel">
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
                            <div class="text-xs" style="color:var(--faint)">{{ $label }}</div>
                            <div class="font-medium">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($poNumbers as $poNumber)
                        <a href="{{ route('po-lookup.show', $poNumber) }}"
                           class="font-mono text-xs px-2 py-1 rounded underline" style="color:var(--teal-2)">{{ $poNumber }}</a>
                    @endforeach
                </div>

                @if($showValue && ($delivery->value_final > 0 || $delivery->value_interim > 0))
                    <p class="text-sm mt-4" style="color:var(--muted)">
                        Booked value <x-money :amount="(float) $delivery->value_interim" :currency="$delivery->currency" /> ·
                        invoiced <x-money :amount="(float) $delivery->value_final" :currency="$delivery->currency" /> ·
                        shortfall <strong><x-money :amount="(float) $delivery->shortfall_value" :currency="$delivery->currency" /></strong>
                    </p>
                @endif
            </div>

            {{-- The delivery date drives the PO's turnaround, so it is correctable (§L, §Q). --}}
            <div class="panel">
                <h3 class="font-semibold">
                    Delivery date
                    @if($delivery->awaitingDeliveryDate())
                        <span class="tag warn">needs entering</span>
                    @endif
                </h3>
                <p class="text-sm mt-1" style="color:var(--muted)">
                    @if($delivery->delivered_on)
                        Currently <strong>{{ $delivery->delivered_on->format('d M Y') }}</strong>{{ $delivery->delivery_date_is_manual ? ' — entered by hand' : ' — read from the final packing list' }}.
                    @elseif($delivery->awaitingDeliveryDate())
                        {{-- The M9 rule, stated where somebody can act on it. --}}
                        <strong>Noon does not tell us when a delivery actually went out.</strong>
                        @if($delivery->estimatedDate())
                            The workbook carries only Noon's <strong>Estimated Delivery Date</strong> of
                            {{ $delivery->estimatedDate()->format('d M Y') }} —
                            <em>{{ \App\Models\Delivery::ESTIMATED_LABEL }}</em> — which is shown as a
                            placeholder and is not measured from.
                        @else
                            The workbook carries no delivery date at all.
                        @endif
                        Type the real date below and this PO's turnaround appears. Nothing is
                        assumed in the meantime: the tool will not invent a date it does not have.
                    @elseif($delivery->planned_date)
                        Only a planned date is known ({{ $delivery->planned_date->format('d M Y') }}), and planned
                        dates get rescheduled, so nothing is measured from it.
                    @else
                        No date is known for this delivery.
                    @endif
                    Turnaround is measured to this date, so correcting it recalculates every PO in the delivery.
                </p>

                @can('manage-fulfillment')
                    <form method="POST" action="{{ route('deliveries.date', $delivery) }}" class="mt-3 flex flex-wrap items-end gap-3">
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
            <div class="panel scroll-x">
                <h3 class="font-semibold text-lg mb-1">What was booked, and what shipped</h3>
                <p class="text-sm mb-4" style="color:var(--muted)">
                    Per SKU, so a shortfall points at the product that caused it.
                </p>

                <table class="tbl">
                    <thead >
                        <tr>
                            <th class="py-2 pe-4">PO</th>
                            <th class="py-2 pe-4">ASIN / NIN</th>
                            <th class="py-2 pe-4 text-right">Booked</th>
                            <th class="py-2 pe-4 text-right">Shipped</th>
                            <th class="py-2 pe-4 text-right">Short</th>
                            @if($showValue)<th class="py-2 text-right">Short value</th>@endif
                        </tr>
                    </thead>
                    <tbody >
                        @foreach($rows as $row)
                            @php($short = max(0, (int) $row->interim - (int) $row->final_qty))
                            <tr>
                                <td class="py-2 pe-4">
                                    <a href="{{ route('po-lookup.show', $row->po_number) }}"
                                       class="font-mono underline" style="color:var(--teal-2)">{{ $row->po_number }}</a>
                                </td>
                                <td class="py-2 pe-4">
                                    <span class="font-mono">{{ $row->sku_id }}</span>
                                    <div class="text-xs" style="color:var(--faint)">{{ Str::limit($row->title, 60) }}</div>
                                </td>
                                <td class="py-2 pe-4 text-right">{{ number_format((int) $row->interim) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format((int) $row->final_qty) }}</td>
                                <td class="py-2 pe-4 text-right {{ $short > 0 && $delivery->has_final ? 'text-amber-800 font-semibold' : '' }}">
                                    {{ $delivery->has_final ? number_format($short) : '—' }}
                                </td>
                                @if($showValue)
                                    <td class="py-2 text-right">
                                        @if($delivery->has_final)
                                            <x-money :amount="$short * (float) $row->unit_cost"
                                                     :currency="$delivery->currency" />
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @unless($delivery->has_final)
                    <p class="text-xs mt-4" style="color:var(--faint)">
                        This delivery has no final packing list yet, so nothing here has shipped and there is
                        no shortfall to show.
                    </p>
                @endunless
            </div>

        </div>
    </div>
</x-operon-page>
