@php
    use App\Http\Controllers\MoneyController;
    use App\Support\Currency;

    $cur = $totals['currency'];
@endphp

<section class="kpis k5">
    <x-kpi label="Invoiced" :prefix="Currency::code($cur).' '"
           :value="number_format($totals['billed'], 0)" tone="n"
           :context="number_format($totals['pos']).' PO(s) in view'" />

    <x-kpi label="Net receivable" :prefix="Currency::code($cur).' '"
           :value="number_format($totals['net_receivable'], 0)" tone="n"
           context="after the marketplace's back margin" />

    <x-kpi label="Our cost" :prefix="Currency::code($cur).' '"
           :value="number_format($totals['cost'], 0)" tone="n"
           :context="$costBasis.' supplier price'" />

    <x-kpi label="Net profit" :prefix="Currency::code($cur).' '"
           :value="number_format($totals['profit'], 0)"
           :tone="$totals['profit'] > 0 ? 'good' : 'bad'"
           :context="number_format($totals['pos_costable']).' of '.number_format($totals['pos']).' PO(s) costable'" />

    <x-kpi label="Margin"
           :value="$totals['margin_pct'] === null ? '—' : $totals['margin_pct']" unit="%"
           :tone="$totals['margin_pct'] === null ? 'n' : ($totals['margin_pct'] > 0 ? 'good' : 'bad')"
           context="profit ÷ what we bank — weighted, not an average of the POs" />
</section>

<x-filters :filters="$filters" :action="route('money.index')"
           :exportHref="route('money.index', array_merge($filters->query(['view' => MoneyController::VIEW_PO]), ['export' => 'csv']))"
           :show="['dates', 'channels', 'fc', 'po', 'brand', 'category']" />

<div class="note">
    <b>A PO is the invoice.</b> Its unit cost is what we bill the marketplace, so the front margin is
    already inside it — what still comes off is the <b>back margin</b>, the channel's cut of the invoice.
    We bill 100 and bank 78. Margin below is profit as a share of what we <b>bank</b>, which is the honest
    denominator; against the billed figure the same PO would look far better than it is.
</div>

@if ($totals['incomplete'] > 0)
    <div class="note warn">
        <b>{{ $totals['incomplete'] }} of {{ $totals['pos'] }} PO(s)</b> have at least one line whose SKU is
        not in the master catalog. Those lines are left out of both the revenue and the cost — never counted
        as pure profit — so their PO's profit covers only part of the order. The coverage column says which.
    </div>
@endif

<x-panel flush title="Every PO" sub="Invoiced → what we bank → what it cost → what it made">
    <div class="scroll-x">
        <table class="tbl">
            <thead>
                <tr>
                    <th>PO</th>
                    <th>Ordered</th>
                    <th>FC</th>
                    <th class="num">Invoiced</th>
                    <th class="num">Marketplace's cut</th>
                    <th class="num">Net receivable</th>
                    <th class="num">Our cost</th>
                    <th class="num">Net profit</th>
                    <th class="num">Margin</th>
                    <th>Coverage</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($statements as $s)
                    @php($po = $s['po'])
                    <tr>
                        <td class="mono">
                            <a href="{{ route('money.po', $po->po_number) }}">{{ $po->po_number }}</a>
                        </td>
                        <td>{{ $po->order_date?->format('d M Y') ?? '—' }}</td>
                        <td>{{ $po->ship_to_fc ?? '—' }}</td>
                        <td class="num">{{ Currency::plain($s['billed'], $s['currency']) }}</td>
                        <td class="num" style="color:var(--muted)">
                            {{ $s['costable'] ? '−'.Currency::plain($s['back_margin_deducted'], $s['currency']) : '—' }}
                        </td>
                        <td class="num">
                            {{ $s['net_receivable'] === null ? '—' : Currency::plain($s['net_receivable'], $s['currency']) }}
                        </td>
                        <td class="num">
                            {{ $s['costable'] ? Currency::plain($s['cost'], $s['currency']) : '—' }}
                        </td>
                        <td class="num">
                            @if ($s['profit'] === null)
                                <span style="color:var(--faint)">—</span>
                            @else
                                <span class="mg {{ $s['profit'] >= 0 ? 'pos' : 'neg' }}">
                                    {{ Currency::plain($s['profit'], $s['currency']) }}
                                </span>
                            @endif
                        </td>
                        <td class="num">
                            @if ($s['margin_pct'] === null)
                                <span class="mg unk">—</span>
                            @else
                                <span class="mg {{ $s['margin_pct'] >= 0 ? 'pos' : 'neg' }}">{{ $s['margin_pct'] }}%</span>
                            @endif
                        </td>
                        <td>
                            @if ($s['coverage']['complete'])
                                <span class="tag good">all {{ $s['coverage']['lines_costed'] }} lines</span>
                            @else
                                <span class="tag warn"
                                      title="{{ number_format($s['coverage']['units_uncosted']) }} units have no catalog cost">
                                    {{ $s['coverage']['lines_costed'] }} of
                                    {{ $s['coverage']['lines_costed'] + $s['coverage']['lines_uncosted'] }} lines
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="empty">No POs match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pg">{{ $orders->links() }}</div>
</x-panel>
