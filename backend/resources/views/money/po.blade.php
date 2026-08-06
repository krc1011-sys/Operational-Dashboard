@php
    use App\Support\Currency;
    use App\Support\MoneyGate;

    $cur = $pnl['currency'];
@endphp

<x-operon-page title="P&amp;L — PO {{ $order->po_number }}"
               sub="{{ $order->order_date?->format('d M Y') ?? 'no order date in the file' }}{{ $order->ship_to_fc ? ' · '.$order->ship_to_fc : '' }} · Admin only">

    <x-slot:controls>
        <a class="pill" href="{{ route('po-lookup.show', $order->po_number) }}">Operational view</a>
        <a class="pill" href="{{ route('money.index') }}">All POs</a>

        <form method="POST" action="{{ route('money-pin.lock') }}" style="margin:0">
            @csrf
            <button class="pill" type="submit" title="Hide money figures now">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                     stroke-width="2" aria-hidden="true">
                    <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                </svg>
                Lock
            </button>
        </form>
    </x-slot:controls>

    <section class="kpis k4">
        <x-kpi label="Invoiced" :prefix="Currency::code($cur).' '"
               :value="number_format($pnl['billed'], 0)" tone="n"
               :context="number_format($pnl['units_costed']).' costable units shipped'" />

        <x-kpi label="Net receivable" :prefix="Currency::code($cur).' '"
               :value="$pnl['net_receivable'] === null ? '—' : number_format($pnl['net_receivable'], 0)" tone="n"
               :context="$pnl['back_margin_pct'] === null ? 'what we bank' : 'after the marketplace\'s '.$pnl['back_margin_pct'].'%'" />

        <x-kpi label="Net profit" :prefix="Currency::code($cur).' '"
               :value="$pnl['profit'] === null ? '—' : number_format($pnl['profit'], 0)"
               :tone="$pnl['profit'] === null ? 'n' : ($pnl['profit'] > 0 ? 'good' : 'bad')"
               :context="'after '.Currency::plain($pnl['cost'], $cur).' of our own cost'" />

        <x-kpi label="Margin"
               :value="$pnl['margin_pct'] === null ? '—' : $pnl['margin_pct']" unit="%"
               :tone="$pnl['margin_pct'] === null ? 'n' : ($pnl['margin_pct'] > 0 ? 'good' : 'bad')"
               context="share of what we bank that we keep" />
    </section>

    <section class="row a">
        <x-panel title="Net P&amp;L" sub="Read down: every line takes from the one above it">
            <x-pnl :statement="$pnl" />
        </x-panel>

        <x-panel title="How the deductions divide"
                 sub="What the marketplace keeps against what we spend ourselves">
            @if ($pnl['costable'])
                @php
                    $bars = collect([
                        ['The marketplace\'s margin', (float) $pnl['back_margin_deducted'], 'var(--amber)'],
                    ])->concat(collect(\App\Services\Margin\ProfitAndLoss::COST_LABELS)
                        ->map(fn ($label, $key) => [$label, (float) ($pnl['cost_breakdown'][$key] ?? 0), 'var(--teal)'])
                        ->values())
                    ->concat([['Net profit', max(0, (float) $pnl['profit']), 'var(--good)']]);

                    $scale = max(0.01, $bars->max(fn ($b) => $b[1]));
                @endphp

                <div style="display:flex;flex-direction:column;gap:10px;margin-top:4px">
                    @foreach ($bars as [$label, $amount, $colour])
                        <div>
                            <div style="display:flex;justify-content:space-between;gap:10px;font-size:11.5px;margin-bottom:4px">
                                <span style="color:var(--muted);font-weight:600">{{ $label }}</span>
                                <span style="font-weight:700;white-space:nowrap">
                                    {{ Currency::plain($amount, $cur) }}
                                    @if ($amount == 0.0 && $label !== 'Net profit')
                                        <span class="tag amber" style="margin-left:4px">until data added</span>
                                    @endif
                                </span>
                            </div>
                            <div style="height:8px;background:var(--surface-3);border-radius:5px;overflow:hidden">
                                <i style="display:block;height:100%;border-radius:5px;
                                          width:{{ round($amount / $scale * 100, 2) }}%;background:{{ $colour }}"></i>
                            </div>
                        </div>
                    @endforeach
                </div>

                <p style="font-size:11px;color:var(--faint);margin-top:14px;line-height:1.6">
                    Bars are to the same scale, so the tallest is the biggest single thing standing between
                    the invoice and the profit. Anything at zero is a cost we do not yet have the data for,
                    not a cost of nothing.
                </p>
            @else
                <x-empty title="Nothing to divide up"
                         note="No line on this PO can be costed, so there is no cost stack to show." />
            @endif
        </x-panel>
    </section>

    <x-panel flush title="Lines" sub="What each SKU on this PO contributed">
        <div class="scroll-x">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th class="num">Shipped</th>
                        <th class="num">Unit price</th>
                        <th class="num">Invoiced</th>
                        <th class="num">Cost / unit</th>
                        <th class="num">Line cost</th>
                        <th>Costable</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->lines()->with('product.economics')->orderBy('sku_id')->get() as $line)
                        @php
                            $marketplace = $line->marketplace instanceof BackedEnum
                                ? $line->marketplace->value : (string) $line->marketplace;
                            $economics = $line->product
                                ? \App\Services\Margin\NetMarginEngine::economicsForPo($line->product, $marketplace)
                                : null;
                            $perUnit = $economics
                                ? \App\Services\Margin\NetMarginEngine::poCostPerUnit($economics) : null;
                        @endphp
                        <tr>
                            <td class="mono">{{ $line->sku_id }}</td>
                            <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                {{ $line->title }}
                            </td>
                            <td class="num">{{ number_format($line->qty_shipped) }}</td>
                            <td class="num">{{ Currency::plain($line->unit_cost, $line->currency) }}</td>
                            <td class="num">{{ Currency::plain($line->qty_shipped * (float) $line->unit_cost, $line->currency) }}</td>
                            <td class="num">
                                {{ $perUnit === null ? '—' : Currency::plain($perUnit, $line->currency) }}
                            </td>
                            <td class="num">
                                {{ $perUnit === null ? '—' : Currency::plain($line->qty_shipped * $perUnit, $line->currency) }}
                            </td>
                            <td>
                                @if ($perUnit !== null)
                                    <span class="tag good">yes</span>
                                @else
                                    <span class="tag warn" title="This SKU is not in the master catalog">not in the catalog</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-panel>
</x-operon-page>
