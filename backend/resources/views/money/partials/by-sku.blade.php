@php
    use App\Http\Controllers\MoneyController;
    use App\Services\Margin\SkuMargin;
    use App\Support\Currency;

    $cur = $summary['currency'];
    $blended = count(SkuMargin::channelsFor($selector)) > 1;
@endphp

<section class="kpis k5">
    <x-kpi label="SKUs" :value="number_format($summary['skus'])" tone="n"
           :context="'on '.\Illuminate\Support\Str::lower(SkuMargin::selectors()[$selector])" />

    <x-kpi label="Profitable" :value="number_format($summary['profitable'])" tone="good"
           :context="$summary['rankable'] > 0 ? round($summary['profitable'] / $summary['rankable'] * 100, 1).'% of the SKUs that can be ranked' : null" />

    <x-kpi label="Losing money" :value="number_format($summary['losing'])"
           :tone="$summary['losing'] > 0 ? 'bad' : 'good'"
           context="margin at or below zero" />

    <x-kpi label="No verdict" :value="number_format($summary['unknown'])" tone="n"
           :context="$summary['bundle_components'] > 0
               ? 'no selling price · plus '.number_format($summary['bundle_components']).' bundle component(s) held out of the ranking'
               : 'no selling price — things we buy and never sell'" />

    <x-kpi label="Blended margin"
           :value="$summary['margin_pct'] === null ? '—' : $summary['margin_pct']" unit="%"
           :tone="$summary['margin_pct'] === null ? 'n' : ($summary['margin_pct'] > 0 ? 'good' : 'bad')"
           context="revenue-weighted across every SKU in view" />
</section>

<x-panel title="Channel" sub="Which channels' economics this screen is answering for" flush>
    <div style="padding:0 18px 16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div class="seg">
            @foreach (SkuMargin::selectors() as $key => $label)
                <a class="{{ $selector === $key ? 'on' : '' }}"
                   href="{{ route('money.index', $filters->query(['view' => MoneyController::VIEW_SKU, 'channel_view' => $key])) }}">{{ $label }}</a>
            @endforeach
        </div>

        <div style="font-size:11.5px;color:var(--muted);flex:1;min-width:220px">
            @if ($selector === SkuMargin::AMAZON)
                Vendor Central <b>and</b> DFS — two channels, so this is already a blend.
            @elseif ($selector === SkuMargin::NOON)
                Noon Retail on its own.
            @else
                All three channels, blended.
            @endif
        </div>
    </div>
</x-panel>

<x-filters :filters="$filters" :action="route('money.index')"
           :exportHref="route('money.index', array_merge($filters->query(['view' => MoneyController::VIEW_SKU, 'channel_view' => $selector]), ['export' => 'csv']))"
           :show="['brand', 'category', 'search', 'skus']" />

@if ($blended)
    <div class="note">
        <b>The blend is revenue-weighted, never an average of the percentages.</b>
        It is <code>Σ (weight × profit) ÷ Σ (weight × net receivable)</code> — so a channel with barely any
        money behind it cannot drag the answer about. A SKU making 30% on 100 units of Amazon and 5% on one
        unit of Noon is making very nearly 30%; a simple mean would call it 17.5% and have us drop a product
        that is doing fine. <b>Unit costs</b> blend over units instead of over money, because a cost per unit
        weighted by revenue would flatter whichever channel charges the most.
    </div>

    @if ($summary['shipped_weighted'] < $summary['skus'])
        <div class="note warn">
            <b>{{ number_format($summary['skus'] - $summary['shipped_weighted']) }} of
            {{ number_format($summary['skus']) }} SKUs</b> have nothing shipped on the channels in view, so
            their blend weights one unit of each channel rather than real volume. Both are revenue
            weightings; the second is the honest one to use when there is no recorded revenue to weight by.
            Every row says which it used. <b>Amazon Retail and Noon Retail both carry real shipped
            units now</b> (M8), so a blend across those two is weighted on money we actually banked.
            <b>DFS has no PO and no shipped units until M9</b>, so it never carries weight yet.
        </div>
    @endif
@endif

@if ($shown < $summary['skus'])
    <div class="note">
        Showing the <b>{{ number_format($shown) }} worst-margin</b> of
        {{ number_format($summary['skus']) }} matching SKUs. Every one of them was costed to work out
        which those are — the KPIs above cover all {{ number_format($summary['skus']) }} — and the
        <a class="link" href="{{ route('money.index', array_merge($filters->query(['view' => 'sku', 'channel_view' => $selector]), ['export' => 'csv'])) }}">CSV export</a>
        carries the full list. Narrow with the filters to bring the rest into view.
    </div>
@endif

<x-panel flush title="{{ $shown < $summary['skus'] ? 'The '.number_format($shown).' worst margins' : 'Every SKU' }}"
         sub="Worst margin first — the point of the screen is finding what loses money">
    <div class="scroll-x">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Brand</th>
                    <th>Channel</th>
                    <th class="num">Units shipped</th>
                    <th class="num">Net receivable / unit</th>
                    <th class="num">Cost / unit</th>
                    <th class="num">Profit / unit</th>
                    <th class="num">Margin</th>
                    <th>Verdict</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php($blend = $row['blend'])
                    <tr class="{{ $blended ? 'blend' : '' }}">
                        <td>
                            <div style="font-weight:650;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                {{ $row['name'] ?: $row['code'] }}
                            </div>
                            <div class="mono" style="color:var(--faint);font-size:10.5px">
                                {{ $row['code'] }}{{ $row['identifiers'] ? ' · '.implode(' ', $row['identifiers']) : '' }}
                            </div>
                            {{--
                                M6 left seven catalog items waiting for a person's
                                decision. A margin computed on one of them is arithmetic
                                over a fiction, so the row says so rather than presenting
                                a confident number nobody should act on.
                            --}}
                            @if ($row['flagged'])
                                <a class="tag warn" style="margin-top:4px"
                                   href="{{ route('master.index', ['flagged' => 1, 'focus' => $row['code']]) }}"
                                   title="This product is flagged for review in the master catalog. Its margin is only as good as the data behind it.">
                                    flagged — check before trusting
                                </a>
                            @endif
                        </td>
                        <td>{{ $row['brand'] ?? '—' }}</td>
                        <td>
                            @if ($blended)
                                <span class="tag teal">blended</span>
                                <div style="font-size:10.5px;color:var(--faint);margin-top:3px">
                                    {{ $blend['weight_basis'] === SkuMargin::BASIS_SHIPPED
                                        ? 'weighted on units shipped'
                                        : 'weighted per unit — nothing shipped' }}
                                </div>
                            @else
                                {{ $row['channels'][0]['label'] ?? '—' }}
                            @endif
                        </td>
                        <td class="num">{{ number_format($blend['units']) }}</td>
                        <td class="num">
                            {{ $blend['net_receivable'] === null ? '—' : Currency::plain($blend['net_receivable'], $row['currency']) }}
                        </td>
                        <td class="num">
                            {{ $blend['cogs'] === null ? '—' : Currency::plain($blend['cogs'], $row['currency']) }}
                        </td>
                        <td class="num">
                            @if ($row['bundle_component'] || $blend['profit'] === null)
                                <span style="color:var(--faint)">—</span>
                            @else
                                <span class="mg {{ $blend['profit'] >= 0 ? 'pos' : 'neg' }}">
                                    {{ Currency::plain($blend['profit'], $row['currency']) }}
                                </span>
                            @endif
                        </td>
                        <td class="num">
                            @if ($row['bundle_component'])
                                {{-- A price that was never charged makes a percentage, not an answer. --}}
                                <span class="mg unk" title="Never sold on its own, so its margin would be computed against a price we never charged">{{ \App\Models\Product::BUNDLE_MARGIN_LABEL }}</span>
                            @elseif ($blend['margin_pct'] === null)
                                <span class="mg unk">—</span>
                            @else
                                <span class="mg {{ $blend['margin_pct'] >= 0 ? 'pos' : 'neg' }}">{{ $blend['margin_pct'] }}%</span>
                            @endif
                        </td>
                        <td>
                            @if ($row['bundle_component'])
                                <span class="tag" title="Its cost is real and still shown; only the margin is withheld">bundle component</span>
                            @elseif ($row['profitable'] === null)
                                <span class="tag" title="No selling price on these channels, so there is no margin to judge">no verdict</span>
                            @elseif ($row['profitable'])
                                <span class="tag good">profitable</span>
                            @else
                                <span class="tag bad">losing money</span>
                            @endif
                        </td>
                    </tr>

                    {{-- The channels the blend is made of, so it can always be checked. --}}
                    @if ($blended)
                        @foreach ($row['channels'] as $channel)
                            <tr class="sub">
                                <td></td>
                                <td></td>
                                <td>{{ $channel['label'] }}</td>
                                <td class="num">{{ number_format($channel['units']) }}</td>
                                <td class="num">
                                    {{ $channel['net_receivable'] === null ? '—' : Currency::plain($channel['net_receivable'], $channel['currency']) }}
                                </td>
                                <td class="num">
                                    {{ $channel['cogs'] === null ? '—' : Currency::plain($channel['cogs'], $channel['currency']) }}
                                </td>
                                <td class="num">
                                    {{ $channel['profit'] === null ? '—' : Currency::plain($channel['profit'], $channel['currency']) }}
                                </td>
                                <td class="num">
                                    {{ $channel['margin_pct'] === null ? '—' : $channel['margin_pct'].'%' }}
                                </td>
                                <td>
                                    @if ($blend['weight_basis'] === SkuMargin::BASIS_SHIPPED && $channel['units'] === 0)
                                        <span style="font-size:10.5px;color:var(--faint)">nothing shipped — no weight in the blend</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @endif
                @empty
                    <tr><td colspan="9" class="empty">No products in the catalog match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="padding:12px 18px 4px">
        <div class="note">
            Cost per unit is the product cost at the <b>{{ $costBasis }}</b> supplier price plus marketing,
            OPEX, packaging and other spend, exactly as the master grid stacks it. Where the master sheet
            carries no figure for one of those, it contributes 0 <b>until data is added</b> — the line is
            already wired, so nothing needs rebuilding when it arrives. A product with several suppliers
            takes the most recent price today and a weighted average across supplier POs in Phase 3.
        </div>
    </div>
</x-panel>
