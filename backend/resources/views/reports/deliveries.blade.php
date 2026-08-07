@php
    use App\Http\Controllers\DeliveriesController as D;
    use App\Support\Currency;

    $showValue = auth()->user()->canSeeOrderValue();
    $booked = $view === D::VIEW_BOOKED;

    $q = fn (array $extra = []) => route('deliveries.index', array_merge(
        $filters->query(), ['view' => $view], $extra
    ));
@endphp

<x-operon-page title="Deliveries"
               sub="{{ $booked ? 'Booked and not yet gone' : 'Shipped' }} · {{ number_format($deliveries->total()) }} in view">
    <x-slot:controls>
        @if (count($views) > 1)
            <div class="seg">
                @foreach ($views as $value => $label)
                    <a class="{{ $view === $value ? 'on' : '' }}"
                       href="{{ route('deliveries.index', array_merge($filters->query(), ['view' => $value])) }}">
                        {{ $label }}<span class="count">{{ number_format($counts[$value] ?? 0) }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </x-slot:controls>

    <div class="note">
        @if ($booked)
            <b>Booked</b> means the units are on an interim packing list for a delivery that
            has not gone yet — so they are already committed to ship. Check this before
            ordering DFS stock for the same SKUs, or the same units get ordered twice.
        @else
            <b>Shipped</b> means a final packing list exists. The shortfall is what was booked
            onto the interim and did not make it onto the final, attributable to the SKU that
            caused it.
        @endif
    </div>

    <x-filters :filters="$filters" :action="route('deliveries.index')"
               :exportHref="$q(['export' => 'csv'])"
               dateLabel="Delivery date"
               :show="['dates', 'channels', 'fc', 'po', 'search', 'skus']" />

    <x-panel flush title="Deliveries by ASN"
             sub="One ASN is one delivery and can carry several POs — open one to see which, and each SKU's shortfall">
        <div class="scroll-x">
            <table class="tbl">
                <thead>
                    <tr>
                        <th style="width:26px"></th>
                        <th>ASN</th>
                        <th>FC</th>
                        <th>POs</th>
                        <th>{{ $booked ? 'Planned' : 'Delivered' }}</th>
                        <th class="num">Booked</th>
                        <th class="num">Shipped</th>
                        <th class="num">Short</th>
                        @if ($showValue)<th class="num">Short value</th>@endif
                        <th>Stage</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($deliveries as $delivery)
                        @php
                            $isOpen = $expanded === (string) $delivery->id;
                            $lines = $breakdown->get($delivery->id, collect());
                            $pos = $delivery->poNumbers();
                        @endphp

                        <tr>
                            <td>
                                <a href="{{ $q(['expand' => $isOpen ? null : $delivery->id]) }}#asn-{{ $delivery->id }}"
                                   id="asn-{{ $delivery->id }}" style="color:var(--muted);display:block"
                                   aria-label="{{ $isOpen ? 'Collapse' : 'Expand' }}">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4"
                                         style="{{ $isOpen ? 'transform:rotate(90deg)' : '' }}"><path d="m9 18 6-6-6-6"/></svg>
                                </a>
                            </td>
                            <td>
                                <a class="mono" href="{{ route('deliveries.show', $delivery) }}">{{ $delivery->asn ?? $delivery->delivery_key }}</a>
                                @if ($delivery->internal_ref)
                                    <div style="font-size:11px;color:var(--faint)">{{ $delivery->internal_ref }}</div>
                                @endif
                            </td>
                            <td>{{ $delivery->fc_code ?? '—' }}</td>
                            <td>
                                @if (count($pos) === 0)
                                    <span style="color:var(--faint)">—</span>
                                @elseif (count($pos) === 1)
                                    <span class="mono">{{ $pos[0] }}</span>
                                @else
                                    <span class="tag teal">{{ count($pos) }} POs</span>
                                @endif
                            </td>
                            <td>
                                {{ $delivery->shownDate()?->format('d M Y') ?? '—' }}
                                @if ($delivery->awaitingDeliveryDate())
                                    {{-- Noon's own estimate, never presented as the real thing (M9). --}}
                                    <span class="tag warn" title="Noon supplies only an estimated delivery date. Open the delivery and enter the real one — turnaround waits on it.">{{ \App\Models\Delivery::ESTIMATED_LABEL }}</span>
                                @elseif ($delivery->fulfilmentDateIsInferred())
                                    <span class="tag warn" title="No date in the file; the upload day stands in">inferred</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format($delivery->units_interim) }}</td>
                            <td class="num">{{ number_format($delivery->units_final) }}</td>
                            <td class="num" style="{{ $delivery->shortfall_units > 0 ? 'color:var(--bad);font-weight:700' : '' }}">
                                {{ $delivery->has_final ? number_format($delivery->shortfall_units) : '—' }}
                            </td>
                            @if ($showValue)
                                <td class="num">
                                    {{ $delivery->has_final ? Currency::plain($delivery->shortfall_value, $delivery->currency) : '—' }}
                                </td>
                            @endif
                            <td>
                                <span class="tag {{ $delivery->has_final ? 'good' : 'teal' }}">
                                    {{ $delivery->has_final ? 'Shipped' : 'Booked' }}
                                </span>
                            </td>
                        </tr>

                        @if ($isOpen)
                            <tr>
                                <td colspan="{{ $showValue ? 10 : 9 }}" style="background:var(--surface-2);padding:0">
                                    <div style="padding:12px 16px 14px">
                                        <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">
                                            {{ count($pos) }} PO(s) under this ASN — {{ implode(' · ', $pos) }}
                                        </div>

                                        <table class="tbl">
                                            <thead>
                                                <tr>
                                                    <th>PO</th>
                                                    <th>SKU</th>
                                                    <th>Title</th>
                                                    <th class="num">Booked</th>
                                                    <th class="num">Shipped</th>
                                                    <th class="num">Short</th>
                                                    @if ($showValue)<th class="num">Short value</th>@endif
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse ($lines as $line)
                                                    @php $short = max(0, $line->interim - $line->final_qty); @endphp
                                                    <tr>
                                                        <td class="mono">{{ $line->po_number }}</td>
                                                        <td class="mono">{{ $line->sku_id }}</td>
                                                        <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                                            {{ $line->title }}
                                                        </td>
                                                        <td class="num">{{ number_format($line->interim) }}</td>
                                                        <td class="num">{{ number_format($line->final_qty) }}</td>
                                                        <td class="num" style="{{ $short > 0 && $delivery->has_final ? 'color:var(--bad);font-weight:700' : '' }}">
                                                            {{ $delivery->has_final ? number_format($short) : '—' }}
                                                        </td>
                                                        @if ($showValue)
                                                            <td class="num">
                                                                {{ $delivery->has_final
                                                                    ? Currency::plain($short * (float) $line->unit_cost, $line->currency)
                                                                    : '—' }}
                                                            </td>
                                                        @endif
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="7" class="empty">No lines on this delivery.</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="10" class="empty">
                                @if ($booked)
                                    Nothing is booked and waiting — every delivery in view has shipped.
                                @else
                                    No shipped deliveries match these filters.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">{{ $deliveries->links() }}</div>
    </x-panel>

    @if ($booked && $committed->isNotEmpty())
        {{-- §R: the DFS overstock answer, per SKU. --}}
        <x-panel flush title="Already committed, per SKU"
                 sub="Units on their way out. Net a DFS order against these before ordering more of the same SKU.">
            <div class="scroll-x">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Title</th>
                            <th class="num">Units committed</th>
                            <th class="num">Deliveries</th>
                            <th class="num">POs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($committed as $row)
                            <tr>
                                <td class="mono">{{ $row->sku_id }}</td>
                                <td style="max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                    {{ $row->title }}
                                </td>
                                <td class="num" style="font-weight:750">{{ number_format($row->units) }}</td>
                                <td class="num">{{ number_format($row->deliveries) }}</td>
                                <td class="num">{{ number_format($row->pos) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($filters->skus !== [])
                <div style="padding:12px 18px 4px">
                    <div class="note">
                        Pasted {{ count($filters->skus) }} identifier(s).
                        Any of them missing from this table has <b>nothing committed</b> — those
                        can be ordered freely.
                    </div>
                </div>
            @endif
        </x-panel>
    @endif
</x-operon-page>
