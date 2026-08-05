@php
    use App\Services\Reporting\FilterSet;

    $showValue = auth()->user()->canSeeOrderValue();
    $days = $order->turnaroundDays();
    $late = $order->isBreachingBenchmark();
@endphp

<x-operon-page title="PO {{ $order->po_number }}">
    

    <div class="op-legacy">
        <div style="display:flex;flex-direction:column;gap:16px">

            <div class="panel">
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 text-sm">
                    @foreach([
                        'Ordered' => $order->order_date?->format('d M Y') ?? 'not in the file',
                        'Fulfilment centre' => $order->ship_to_fc ?? '—',
                        'Vendor code' => $order->vendor_code ?? '—',
                        'Status' => $order->status ?? '—',
                        'Confirmation rate' => $order->confirmationRate() === null ? '—' : $order->confirmationRate() . '%',
                        'Cancellation deadline' => $order->cancellation_deadline?->format('d M Y') ?? '—',
                    ] as $label => $value)
                        <div>
                            <div class="text-xs" style="color:var(--faint)">{{ $label }}</div>
                            <div class="font-medium">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- §L: the headline turnaround, and the responsiveness measure beside it. --}}
                <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-kpi-tile
                        label="Turnaround"
                        :value="$days === null ? '—' : $days . ' days'"
                        :sub="$order->is_complete
                            ? 'Completed ' . ($order->completed_on?->format('d M Y') ?? 'date unknown')
                            : 'Still open — and counting'"
                        :target="$benchmark . ' days'"
                        :status="$days === null ? 'neutral' : ($late ? 'bad' : 'good')" />

                    <x-kpi-tile
                        label="First shipment"
                        :value="$order->first_shipped_on?->format('d M Y') ?? 'Nothing shipped yet'"
                        :sub="$order->daysToFirstShipment() === null ? null : $order->daysToFirstShipment() . ' days after the PO'" />

                    <x-kpi-tile
                        label="Deliveries"
                        :value="$deliveries->count()"
                        sub="ASNs this PO's units went into" />
                </div>

                @if($order->order_date === null)
                    <p class="mt-4 text-xs note warn p-3" style="color:var(--warn)">
                        This PO has no order date — the single-PO export does not carry one. It shows its
                        completion date but no day count. Upload the same PO from the bulk export, or type
                        the PO date on the upload form, and turnaround appears.
                    </p>
                @endif
            </div>

            {{-- Every ASN this PO went into, with its own date and units (§L). --}}
            <div class="panel scroll-x">
                <h3 class="font-semibold text-lg mb-1">Deliveries</h3>
                <p class="text-sm mb-4" style="color:var(--muted)">
                    One PO's units can be split across several deliveries over time. These are this
                    PO's units only — each delivery may also carry other POs.
                </p>

                <table class="tbl">
                    <thead >
                        <tr>
                            <th class="py-2 pe-4">ASN</th>
                            <th class="py-2 pe-4">Reference</th>
                            <th class="py-2 pe-4">FC</th>
                            <th class="py-2 pe-4">Date</th>
                            <th class="py-2 pe-4 text-right">Booked here</th>
                            <th class="py-2 text-right">Shipped here</th>
                        </tr>
                    </thead>
                    <tbody >
                        @forelse($byDelivery as $deliveryId => $stages)
                            @php($delivery = $deliveries[$deliveryId] ?? null)
                            @continue($delivery === null)
                            <tr>
                                <td class="py-2 pe-4">
                                    <a href="{{ route('deliveries.show', $delivery) }}"
                                       class="font-mono underline" style="color:var(--teal-2)">{{ $delivery->asn ?? $delivery->delivery_key }}</a>
                                </td>
                                <td class="py-2 pe-4">{{ $delivery->internal_ref ?? '—' }}</td>
                                <td class="py-2 pe-4">{{ $delivery->fc_code ?? '—' }}</td>
                                <td class="py-2 pe-4">
                                    @if($delivery->fulfilmentDate())
                                        {{ $delivery->fulfilmentDate()->format('d M Y') }}
                                        @if($delivery->fulfilmentDateIsInferred())
                                            <span class="text-xs" style="color:var(--faint)">(upload day)</span>
                                        @endif
                                    @elseif($delivery->planned_date)
                                        {{ $delivery->planned_date->format('d M Y') }}
                                        <span class="text-xs" style="color:var(--faint)">(planned — may change)</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 pe-4 text-right">
                                    {{ number_format((int) ($stages->firstWhere('stage', $interim)->units ?? 0)) }}
                                </td>
                                <td class="py-2 text-right font-medium">
                                    {{ number_format((int) ($stages->firstWhere('stage', $final)->units ?? 0)) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center" style="color:var(--faint)">
                                    Nothing booked into a delivery yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- §F: the real numbers side by side, not just a state label. --}}
            <div class="panel scroll-x">
                <h3 class="font-semibold text-lg mb-4">Lines</h3>

                <table class="tbl">
                    <thead >
                        <tr>
                            <th class="py-2 pe-4">ASIN / NIN</th>
                            <th class="py-2 pe-4 text-right">Requested</th>
                            <th class="py-2 pe-4 text-right">Accepted</th>
                            <th class="py-2 pe-4 text-right">Cancelled</th>
                            <th class="py-2 pe-4 text-right">Net</th>
                            <th class="py-2 pe-4 text-right">Booked</th>
                            <th class="py-2 pe-4 text-right">Shipped</th>
                            <th class="py-2 pe-4 text-right">Not booked</th>
                            <th class="py-2 pe-4 text-right">Fill %</th>
                            <th class="py-2">State</th>
                        </tr>
                    </thead>
                    <tbody >
                        @foreach($lines as $line)
                            <tr>
                                <td class="py-2 pe-4">
                                    <span class="font-mono">{{ $line->sku_id }}</span>
                                    <div class="text-xs" style="color:var(--faint)">{{ Str::limit($line->title, 60) }}</div>
                                </td>
                                <td class="py-2 pe-4 text-right">{{ number_format($line->qty_requested) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($line->qty_accepted) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($line->qty_cancelled_honoured) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($line->qty_net_accepted) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($line->qty_booked) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($line->qty_shipped) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($line->qty_not_booked) }}</td>
                                <td class="py-2 pe-4 text-right">
                                    {{ $line->fill_rate_pct === null ? '—' : round((float) $line->fill_rate_pct, 1) . '%' }}
                                </td>
                                <td class="py-2">
                                    {{ FilterSet::lineStates()[$line->line_state] ?? $line->line_state }}
                                    @if($line->has_chargeback_flag)
                                        <span class="ms-1 text-xs bg-red-100 px-2 py-0.5 rounded-full" style="color:var(--bad)">chargeback</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if($showValue)
                    <p class="text-xs mt-4" style="color:var(--faint)">
                        Marketplace value of what has shipped:
                        <strong><x-money :amount="$lines->sum(fn ($l) => $l->qty_shipped * (float) $l->unit_cost)"
                                         :currency="\App\Support\Currency::single($lines->pluck('currency'))"
                                         :mixed="\App\Support\Currency::single($lines->pluck('currency')) === null" /></strong>.
                        Margin and profitability are Admin-only and behind the PIN.
                    </p>
                @endif
            </div>

        </div>
    </div>
</x-operon-page>
