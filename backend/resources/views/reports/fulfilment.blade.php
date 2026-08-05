@php
    use App\Http\Controllers\FulfilmentController as F;
    use App\Services\Reporting\FilterSet;
    use App\Support\Currency;

    $showValue = auth()->user()->canSeeOrderValue();
    $cur = $totals['currency'];
    $benchmark = $benchmarks['turnaround_days'];

    $q = fn (array $extra = []) => route('fulfilment.index', array_merge(
        $filters->query(), ['view' => $status], $extra
    ));
@endphp

<x-operon-page title="Fulfilment"
               sub="{{ number_format($orders->count()) }} purchase orders · {{ number_format($counts[$status] ?? 0) }} lines in view">
    <x-slot:controls>
        {{-- The status toggle (§8). A user with only view-pending sees one option, which
             is the screen §O gave them, so the toggle collapses rather than misleads. --}}
        @if (count($statuses) > 1)
            <div class="seg">
                @foreach ($statuses as $value => $label)
                    <a class="{{ $status === $value ? 'on' : '' }}"
                       href="{{ route('fulfilment.index', array_merge($filters->query(), ['view' => $value])) }}">
                        {{ $label }}<span class="count">{{ number_format($counts[$value] ?? 0) }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </x-slot:controls>

    <section class="kpis k4">
        <x-kpi label="Fill rate"
               :value="$totals['fill_rate'] === null ? '—' : $totals['fill_rate']"
               :unit="$totals['fill_rate'] === null ? null : '%'"
               :tone="$totals['fill_rate'] === null ? 'n' : ($totals['fill_rate'] >= $benchmarks['fill_rate_target'] ? 'good' : 'bad')"
               :context="number_format($totals['shipped']).' of '.number_format($totals['net_accepted']).' net accepted'" />

        <x-kpi label="Not booked" :value="number_format($totals['not_booked'])" unit=" units"
               :tone="$totals['not_booked'] > 0 ? 'warn' : 'good'"
               context="accepted, on no delivery yet" />

        <x-kpi label="Shortfall" :value="number_format($totals['shortfall_units'])" unit=" units"
               :tone="$totals['shortfall_units'] > 0 ? 'bad' : 'good'"
               :context="$showValue ? Currency::plain($totals['shortfall_value'], $cur).' of accepted units' : 'accepted but not shipped'" />

        <x-kpi label="Cancelled" :value="number_format($totals['cancelled'])" unit=" units"
               tone="n" context="honoured cancellations, netted off" />
    </section>

    <x-filters :filters="$filters" :action="route('fulfilment.index')"
               :exportHref="$q(['export' => 'csv'])"
               :show="['dates', 'channels', 'fc', 'brand', 'category', 'po', 'search', 'skus']" />

    <x-panel flush
             :title="$status === F::STATUS_OUTSTANDING ? 'Purchase orders with units not yet booked' : 'Purchase orders'"
             :sub="$status === F::STATUS_OUTSTANDING
                    ? 'What has been accepted and is not on any delivery — worst value first'
                    : 'Open one to see its lines and where each has got to — worst shortfall first'">

        <div class="scroll-x">
            <table class="tbl">
                <thead>
                    <tr>
                        <th style="width:26px"></th>
                        <th>PO</th>
                        <th>FC</th>
                        <th>Ordered</th>
                        <th class="num">Lines</th>
                        <th class="num">Net accepted</th>
                        <th class="num">Booked</th>
                        <th class="num">Shipped</th>
                        <th class="num">Not booked</th>
                        <th>Fill rate</th>
                        @if ($showValue)<th class="num">Shortfall</th>@endif
                        <th>Turnaround</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $po)
                        @php
                            $f = $po->figures;
                            $isOpen = $expanded === $po->po_number;
                            $days = $po->is_complete ? $po->days_to_complete : $po->daysOpen();
                            $late = $days !== null && $days > $benchmark;
                        @endphp

                        <tr>
                            <td>
                                <a href="{{ $q(['expand' => $isOpen ? null : $po->po_number]) }}#po-{{ $po->po_number }}"
                                   id="po-{{ $po->po_number }}" style="color:var(--muted);display:block"
                                   aria-label="{{ $isOpen ? 'Collapse' : 'Expand' }} {{ $po->po_number }}">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4"
                                         style="{{ $isOpen ? 'transform:rotate(90deg)' : '' }}"><path d="m9 18 6-6-6-6"/></svg>
                                </a>
                            </td>
                            <td>
                                <a class="mono" href="{{ route('po-lookup.show', $po->po_number) }}">{{ $po->po_number }}</a>
                                @if ($f['chargeback'])<span class="tag bad" style="margin-left:5px">chargeback</span>@endif
                            </td>
                            <td>
                                @if ($f['fc_count'] > 1)
                                    <span class="tag">{{ $f['fc_count'] }} FCs</span>
                                @else
                                    {{ $f['fc'] ?? '—' }}
                                @endif
                            </td>
                            <td>{{ $po->order_date?->format('d M Y') ?? '—' }}</td>
                            <td class="num">{{ number_format($f['lines']) }}</td>
                            <td class="num">{{ number_format($f['net_accepted']) }}</td>
                            <td class="num">{{ number_format($f['booked']) }}</td>
                            <td class="num">{{ number_format($f['shipped']) }}</td>
                            <td class="num" style="{{ $f['not_booked'] > 0 ? 'color:var(--warn)' : '' }}">
                                {{ number_format($f['not_booked']) }}
                            </td>
                            <td>
                                @if ($f['fill_rate'] === null)
                                    <span style="color:var(--faint)">—</span>
                                @else
                                    <x-mini-bar :pct="$f['fill_rate']" :target="$benchmarks['fill_rate_target']" />
                                    {{ $f['fill_rate'] }}%
                                @endif
                            </td>
                            @if ($showValue)
                                <td class="num" style="{{ $f['shortfall_value'] > 0 ? 'color:var(--bad)' : '' }}">
                                    {{ Currency::plain($f['shortfall_value'], $f['currency']) }}
                                </td>
                            @endif
                            <td>
                                @if ($days === null)
                                    <span style="color:var(--faint)">no PO date</span>
                                @else
                                    <span class="tag {{ $late ? 'bad' : 'good' }}">
                                        {{ $days }}d{{ $po->is_complete ? '' : ' open' }}
                                    </span>
                                @endif
                            </td>
                        </tr>

                        @if ($isOpen)
                            {{-- The drill: this PO's lines, and where each one stands. --}}
                            <tr>
                                <td colspan="{{ $showValue ? 12 : 11 }}" style="background:var(--surface-2);padding:0">
                                    <div style="padding:12px 16px 14px">
                                        <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">
                                            {{ number_format($expandedLines->count()) }} lines on {{ $po->po_number }}
                                        </div>

                                        <table class="tbl">
                                            <thead>
                                                <tr>
                                                    <th>SKU</th>
                                                    <th>Title</th>
                                                    <th>State</th>
                                                    <th class="num">Accepted</th>
                                                    <th class="num">Cancelled</th>
                                                    <th class="num">Booked</th>
                                                    <th class="num">Shipped</th>
                                                    <th class="num">Not booked</th>
                                                    <th class="num">Short</th>
                                                    @if ($showValue)<th class="num">Short value</th>@endif
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($expandedLines as $line)
                                                    @php $short = max(0, $line->qty_net_accepted - $line->qty_shipped); @endphp
                                                    <tr>
                                                        <td class="mono">{{ $line->sku_id }}</td>
                                                        <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                                            {{ $line->title }}
                                                        </td>
                                                        <td>
                                                            <span class="tag {{ match ($line->line_state) { 'dispatched' => 'good', 'scheduled' => 'teal', 'cancelled' => 'bad', default => 'warn' } }}">
                                                                {{ FilterSet::lineStates()[$line->line_state] ?? $line->line_state }}
                                                            </span>
                                                        </td>
                                                        <td class="num">{{ number_format($line->qty_net_accepted) }}</td>
                                                        <td class="num">{{ number_format($line->qty_cancelled_honoured) }}</td>
                                                        <td class="num">{{ number_format($line->qty_booked) }}</td>
                                                        <td class="num">{{ number_format($line->qty_shipped) }}</td>
                                                        <td class="num" style="{{ $line->qty_not_booked > 0 ? 'color:var(--warn)' : '' }}">
                                                            {{ number_format($line->qty_not_booked) }}
                                                        </td>
                                                        <td class="num" style="{{ $short > 0 ? 'color:var(--bad);font-weight:700' : '' }}">
                                                            {{ number_format($short) }}
                                                        </td>
                                                        @if ($showValue)
                                                            <td class="num">
                                                                {{ Currency::plain($short * (float) $line->unit_cost, $line->currency) }}
                                                            </td>
                                                        @endif
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="12" class="empty">
                                @if ($status === F::STATUS_OUTSTANDING)
                                    Nothing outstanding — every accepted unit in view is booked onto a delivery.
                                @else
                                    No purchase orders match these filters.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>

    @if ($status === F::STATUS_OUTSTANDING)
        <div class="note">
            <b>This is the old Pending tab.</b> Same rows, same rule — accepted units that are
            on no delivery yet — now reachable from the same place as everything else about a PO.
        </div>
    @endif
</x-operon-page>
