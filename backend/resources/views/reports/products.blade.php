@php
    use App\Support\Currency;

    $showValue = auth()->user()->canSeeOrderValue();
    $cur = $totals['currency'];
    $target = $benchmarks['fill_rate_target'];

    /*
     * M9 headline sell-through, blended across only the channels that HAVE an honest
     * one. A channel whose sell-in and sell-out cover different days is left out of the
     * blend and listed with its reason instead — see SellThroughEngine.
     */
    $comparable = $channels->filter(fn ($c) => $c['sell_through_pct'] !== null);
    $comparableDenominator = (int) $comparable->sum('sell_through_denominator');
    $headlineSellThrough = $comparableDenominator > 0
        ? round($comparable->sum('sell_out_units') / $comparableDenominator * 100, 1)
        : null;

    $sellThroughContext = match (true) {
        ! $sellOutLoaded => 'upload a sell-out report',
        $headlineSellThrough !== null => 'sold out ÷ received, over the same window',
        default => 'sell-out loaded, but no window lines up yet',
    };

    // Which quadrant the panel is drawing. The controller decides; the view only draws.
    $isVelocity = ($quadrant['mode'] ?? 'fill_rate') === 'velocity';

    $totalSellOutUnits = (int) $channels->sum('sell_out_units');
    $totalSellOutRevenue = (float) $channels->sum('sell_out_revenue');
    $limits = $watchlists['thresholds'] ?? config('operon.cover');
@endphp

<x-operon-page title="Products"
               sub="{{ number_format($skus->count()) }} SKUs ordered · {{ number_format($catalog['products']) }} in the catalog">

    <section class="kpis k5">
        <x-kpi label="SKUs ordered" :value="number_format($totals['sku_count'])" tone="n"
               :context="number_format($catalog['products']).' products in the catalog'" />

        <x-kpi label="Brands" :value="number_format($catalog['brands'])" tone="n"
               :context="number_format($catalog['categories']).' categories · '.number_format($catalog['sub_categories']).' sub-categories'" />

        <x-kpi label="Sell-in" :prefix="Currency::code($cur).' '"
               :value="number_format($totals['shipped_value'], 0)" tone="n"
               :context="number_format($totals['shipped']).' units shipped to channels'" />

        <x-kpi label="Sell-through"
               :value="$headlineSellThrough ?? '—'"
               :unit="$headlineSellThrough === null ? null : '%'"
               :tone="$headlineSellThrough === null ? 'n' : 'warn'"
               :context="$sellThroughContext" />

        <x-kpi label="Not shipped" :value="number_format($totals['shortfall_units'])" unit=" units"
               :tone="$totals['shortfall_units'] > 0 ? 'bad' : 'good'"
               :context="$showValue ? Currency::plain($totals['shortfall_value'], $cur).' of accepted units' : 'accepted but not shipped'" />
    </section>

    <x-filters :filters="$filters" :action="route('products.index')"
               :exportHref="route('products.index', array_merge($filters->query(), ['export' => 'csv']))"
               :show="['dates', 'channels', 'fc', 'brand', 'category', 'search', 'skus']" />

    @unless ($sellOutLoaded)
        <div class="note warn">
            <b>No sell-out has been uploaded yet.</b> Everything here measures <b>sell-in</b> — what
            we shipped to the channels. Sell-through, days of cover and the velocity quadrant need
            a sell-out report: the Amazon <em>Sales by ASIN</em> export, the DFS orders file, or the
            Noon sell-out workbook. Until one arrives the quadrant below plots what we can measure
            today — how much of each SKU the channel orders against how reliably we fill it.
        </div>
    @endunless

    <section class="row a">
        {{-- The labelled quadrant (§1: no chart a teammate has to decode).
             Two plots share this panel: the M9 velocity-against-stock one when sell-out
             is loaded, and M5's volume-against-fill-rate one when it is not. --}}
        <x-panel :title="$isVelocity ? 'How fast it sells against how much we hold' : 'Volume against fill rate'"
                 :sub="$isVelocity
                    ? 'Each dot is one SKU on one channel. Bottom-right is selling fast on thin stock — reorder. Top-left is a lot of stock going nowhere.'
                    : 'Each dot is one SKU. Top-right is high volume filled well; bottom-right is high volume we keep missing.'">
            @if ($quadrant['points']->isNotEmpty() && $isVelocity)
                @php $pts = $quadrant['points']; @endphp

                <div style="position:relative;height:330px;margin:6px 4px 0 44px">
                    <svg viewBox="0 0 100 100" preserveAspectRatio="none"
                         style="position:absolute;inset:0;width:100%;height:100%" aria-hidden="true">
                        @foreach ([0, 25, 50, 75, 100] as $g)
                            <line x1="0" y1="{{ 100 - $g }}" x2="100" y2="{{ 100 - $g }}"
                                  stroke="var(--border)" stroke-width=".3" stroke-dasharray="1.5 1.5"/>
                            <line x1="{{ $g }}" y1="0" x2="{{ $g }}" y2="100"
                                  stroke="var(--border)" stroke-width=".3" stroke-dasharray="1.5 1.5"/>
                        @endforeach
                    </svg>

                    {{-- Named corners, per §1: nobody should have to decode "top right". --}}
                    <div style="position:absolute;left:6px;top:4px;font-size:10px;font-weight:700;color:var(--faint)">SITTING ON IT</div>
                    <div style="position:absolute;right:6px;top:4px;font-size:10px;font-weight:700;color:var(--amber-2);text-align:right">OVERSTOCKED ON A GOOD SELLER</div>
                    <div style="position:absolute;left:6px;bottom:16px;font-size:10px;font-weight:700;color:var(--faint)">QUIET</div>
                    <div style="position:absolute;right:6px;bottom:16px;font-size:10px;font-weight:700;color:var(--bad);text-align:right">RUNNING HOT — reorder</div>

                    <div style="position:absolute;left:-44px;top:-6px;font-size:10px;color:var(--faint)">{{ number_format($quadrant['max_units']) }}</div>
                    <div style="position:absolute;left:-44px;bottom:-6px;font-size:10px;color:var(--faint)">0 units</div>

                    @foreach ($pts as $p)
                        <div title="{{ $p['title'] ?: $p['sku_id'] }} — {{ $p['channel']->label() }}: {{ number_format($p['soh_units']) }} units held, selling {{ number_format($p['run_rate'], 2) }}/day{{ $p['cover_days'] === null ? '' : ', '.number_format($p['cover_days'], 1).' days of cover' }}{{ $p['stock_is_provisional'] ? ' (stock provisional)' : '' }}"
                             style="position:absolute;
                                    left:{{ max(0.5, min(98, $p['x'])) }}%;
                                    top:{{ max(1, min(97, 100 - $p['y'])) }}%;
                                    width:9px;height:9px;margin:-4.5px 0 0 -4.5px;border-radius:50%;
                                    background:{{ $p['risk'] ? 'var(--bad)' : ($p['warn'] ? 'var(--amber)' : 'var(--teal)') }};
                                    opacity:{{ $p['stock_is_provisional'] ? '.45' : '.78' }};cursor:default"></div>

                        @if (($p['risk'] || $p['warn']) && $loop->index < 18)
                            <div style="position:absolute;
                                        left:calc({{ max(0.5, min(98, $p['x'])) }}% + 8px);
                                        top:calc({{ max(1, min(97, 100 - $p['y'])) }}% - 7px);
                                        font-size:9.5px;font-weight:650;
                                        color:{{ $p['risk'] ? 'var(--bad)' : 'var(--amber-2)' }};
                                        white-space:nowrap;pointer-events:none;max-width:150px;overflow:hidden;text-overflow:ellipsis">
                                {{ \Illuminate\Support\Str::limit(strip_tags($p['title'] ?: $p['sku_id']), 24) }}
                            </div>
                        @endif
                    @endforeach
                </div>

                <div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--faint);margin:8px 0 0 44px">
                    <span>slower</span>
                    <span>up to {{ number_format($quadrant['max_rate'], 1) }} units a day</span>
                </div>

                <div style="display:flex;gap:16px;margin-top:12px;font-size:11px;color:var(--muted);flex-wrap:wrap">
                    <span><i style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--bad);margin-right:5px"></i>
                        under {{ $limits['stockout_days'] }} days of cover</span>
                    <span><i style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--amber);margin-right:5px"></i>
                        overstocking</span>
                    <span><i style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--teal);margin-right:5px"></i>
                        healthy</span>
                    <span style="color:var(--faint)">faded dots are DFS — provisional stock</span>
                </div>
            @elseif ($quadrant['points']->isNotEmpty())
                @php $pts = $quadrant['points']; @endphp

                <div style="position:relative;height:330px;margin:6px 4px 0 34px">
                    {{-- Gridlines and the fill-rate target, faint and dashed per §6. --}}
                    <svg viewBox="0 0 100 100" preserveAspectRatio="none"
                         style="position:absolute;inset:0;width:100%;height:100%" aria-hidden="true">
                        @foreach ([0, 25, 50, 75, 100] as $g)
                            <line x1="0" y1="{{ 100 - $g }}" x2="100" y2="{{ 100 - $g }}"
                                  stroke="var(--border)" stroke-width=".3" stroke-dasharray="1.5 1.5"/>
                        @endforeach
                        <line x1="0" y1="{{ 100 - $quadrant['target'] }}" x2="100" y2="{{ 100 - $quadrant['target'] }}"
                              stroke="var(--amber)" stroke-width=".5" stroke-dasharray="2 1.5"/>
                    </svg>

                    {{-- Axis labels --}}
                    <div style="position:absolute;left:-34px;top:-6px;font-size:10px;color:var(--faint)">100%</div>
                    <div style="position:absolute;left:-34px;top:{{ 100 - $quadrant['target'] }}%;font-size:10px;color:var(--amber-2);font-weight:700">{{ $target }}%</div>
                    <div style="position:absolute;left:-34px;bottom:-6px;font-size:10px;color:var(--faint)">0%</div>

                    @foreach ($pts as $p)
                        <div title="{{ $p['title'] }} — {{ number_format($p['accepted']) }} units accepted, {{ $p['fill_rate'] }}% filled"
                             style="position:absolute;
                                    left:{{ max(0.5, min(98, $p['x'])) }}%;
                                    top:{{ max(1, min(97, 100 - $p['y'])) }}%;
                                    width:9px;height:9px;margin:-4.5px 0 0 -4.5px;border-radius:50%;
                                    background:{{ $p['risk'] ? 'var(--bad)' : 'var(--teal)' }};
                                    opacity:.78;cursor:default"></div>

                        {{-- Label the ones that matter: big volume we are missing (§1). --}}
                        @if ($p['risk'] && $loop->index < 22)
                            <div style="position:absolute;
                                        left:calc({{ max(0.5, min(98, $p['x'])) }}% + 8px);
                                        top:calc({{ max(1, min(97, 100 - $p['y'])) }}% - 7px);
                                        font-size:9.5px;font-weight:650;color:var(--bad);
                                        white-space:nowrap;pointer-events:none;max-width:150px;overflow:hidden;text-overflow:ellipsis">
                                {{ \Illuminate\Support\Str::limit(strip_tags($p['title'] ?: $p['sku_id']), 26) }}
                            </div>
                        @endif
                    @endforeach
                </div>

                <div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--faint);margin:8px 0 0 34px">
                    <span>fewer units ordered</span>
                    <span>up to {{ number_format($quadrant['max_units']) }} units</span>
                </div>

                <div style="display:flex;gap:16px;margin-top:12px;font-size:11px;color:var(--muted)">
                    <span><i style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--bad);margin-right:5px"></i>
                        high volume, filling below {{ $target }}%</span>
                    <span><i style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--teal);margin-right:5px"></i>
                        everything else</span>
                </div>
            @else
                <x-empty title="Nothing to plot yet"
                         note="A SKU needs accepted units before its fill rate means anything." />
            @endif
        </x-panel>

        {{-- Brand rollup - one of the things loading the catalog switched on. --}}
        <x-panel title="By brand" sub="Where the volume sits, and where it is being missed">
            @if ($brands->isNotEmpty())
                <div class="scroll-x">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>Brand</th>
                                <th class="num">SKUs</th>
                                <th class="num">Shipped</th>
                                <th>Fill rate</th>
                                @if ($showValue)<th class="num">Short</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($brands->take(14) as $row)
                                <tr>
                                    <td style="font-weight:650">{{ $row['key'] }}</td>
                                    <td class="num">{{ number_format($row['sku_count']) }}</td>
                                    <td class="num">{{ number_format($row['shipped']) }}</td>
                                    <td>
                                        @if ($row['fill_rate'] === null)
                                            <span style="color:var(--faint)">—</span>
                                        @else
                                            <x-mini-bar :pct="$row['fill_rate']" :target="$target" />
                                            {{ $row['fill_rate'] }}%
                                        @endif
                                    </td>
                                    @if ($showValue)
                                        <td class="num" style="{{ $row['shortfall_value'] > 0 ? 'color:var(--bad)' : '' }}">
                                            {{ Currency::plain($row['shortfall_value'], $row['currency']) }}
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-empty title="No brands yet"
                         note="Brands come from the master catalog. Upload it and this fills in." />
            @endif
        </x-panel>
    </section>

    <section class="row b">
        <x-panel title="By category" sub="The same question one level up">
            <div class="scroll-x">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="num">SKUs</th>
                            <th class="num">Accepted</th>
                            <th class="num">Shipped</th>
                            <th>Fill rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($categories->take(14) as $row)
                            <tr>
                                <td style="font-weight:650">{{ $row['key'] }}</td>
                                <td class="num">{{ number_format($row['sku_count']) }}</td>
                                <td class="num">{{ number_format($row['net_accepted']) }}</td>
                                <td class="num">{{ number_format($row['shipped']) }}</td>
                                <td>
                                    @if ($row['fill_rate'] === null)
                                        <span style="color:var(--faint)">—</span>
                                    @else
                                        <x-mini-bar :pct="$row['fill_rate']" :target="$target" />
                                        {{ $row['fill_rate'] }}%
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="empty">No categories in view.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>

        {{-- Sell-in vs sell-out, per channel and live since M9. --}}
        <x-panel title="Sell-in vs sell-out"
                 sub="What each channel took in against what its customers bought">
            @if ($sellOutLoaded)
                <div class="stbanner">
                    <div>
                        <div class="stbig">
                            @if ($headlineSellThrough !== null)
                                {{ $headlineSellThrough }}<small>%</small>
                            @else
                                —
                            @endif
                        </div>
                    </div>
                    <div class="stbar">
                        <div class="t">
                            <span>Received in — {{ $comparableDenominator ? number_format($comparableDenominator).' units' : 'no aligned window' }}</span>
                            <span>Sold out — {{ number_format($totalSellOutUnits) }} units · {{ Currency::plain($totalSellOutRevenue, $cur) }}</span>
                        </div>
                        <div class="track">
                            @if ($headlineSellThrough !== null)
                                <i style="width:{{ min(100, $headlineSellThrough) }}%"></i>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="scroll-x" style="margin-top:12px">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>Channel</th>
                                <th class="num">Sold out</th>
                                <th class="num">Received in</th>
                                <th>Sell-through</th>
                                <th class="num">Cover</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($channels as $c)
                                <tr>
                                    <td style="font-weight:650">
                                        {{ $c['channel']->label() }}
                                        @if ($c['stock_is_provisional'])
                                            <span class="tag amber" title="{{ $c['stock_note'] }}">provisional stock</span>
                                        @endif
                                    </td>
                                    <td class="num">{{ number_format($c['sell_out_units']) }}</td>
                                    <td class="num">{{ $c['sell_through_denominator'] === null ? '—' : number_format($c['sell_through_denominator']) }}</td>
                                    <td>
                                        @if ($c['sell_through_pct'] !== null)
                                            <x-mini-bar :pct="min(100, $c['sell_through_pct'])" :target="100" />
                                            {{ $c['sell_through_pct'] }}%
                                        @else
                                            <span style="color:var(--faint)">not comparable</span>
                                        @endif
                                    </td>
                                    <td class="num">
                                        {{ $c['cover_days'] === null ? '—' : number_format($c['cover_days'], 1).' d' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @foreach ($channels->filter(fn ($c) => $c['sell_through_pct'] === null && $c['sell_through_note']) as $c)
                    <div class="note" style="margin-top:10px">
                        <b>{{ $c['channel']->label() }} — no sell-through figure.</b>
                        {{ $c['sell_through_note'] }}
                    </div>
                @endforeach

                @if ($comparable->isNotEmpty())
                    <div class="note" style="margin-top:10px">
                        <b>The denominator is stated, not assumed.</b>
                        {{ $comparable->first()['channel']->label() }} divides by
                        {{ $comparable->first()['sell_through_basis'] }} — the only figure that
                        covers the same days as the sell-out it is compared against.
                    </div>
                @endif
            @else
                <div class="stbanner">
                    <div><div class="stbig">—</div></div>
                    <div class="stbar">
                        <div class="t">
                            <span>Sell-in — {{ Currency::plain($totals['shipped_value'], $cur) }}</span>
                            <span>Sell-out — not loaded</span>
                        </div>
                        <div class="track"></div>
                    </div>
                </div>

                <x-empty title="No sell-out has been uploaded yet"
                         note="Sell-through, days of cover and the overstocking and under-supplying watchlists all need it. Everything on this page that does not depend on it is live now." />
            @endif
        </x-panel>
    </section>

    {{-- ══ The watchlists (§D). Named lists, each row carrying WHY it is on the list. ══ --}}
    <section class="row a" id="watchlists">
        <x-panel title="Overstocking"
                 :sub="'More than '.$limits['overstock_days'].' days of cover, stock Amazon says has aged 90+ days, or stock that sold nothing at all'">
            @if ($watchlists && $watchlists['overstocking']['all']->isNotEmpty())
                <div style="font-size:11px;color:var(--muted);margin-bottom:10px">
                    <b>{{ number_format($watchlists['overstocking']['all']->count()) }} SKUs</b>
                    holding <b>{{ number_format($watchlists['overstocking']['units']) }} units</b>
                    @foreach ($watchlists['overstocking']['by_channel'] as $ch => $group)
                        · {{ \App\Enums\Channel::from($ch)->label() }} {{ $group->count() }}
                    @endforeach
                </div>
                <div class="scroll-x">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Channel</th>
                                <th class="num">Held</th>
                                <th class="num">Cover</th>
                                <th>Why it is here</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($watchlists['overstocking']['all']->take(20) as $r)
                                <tr>
                                    <td class="mono">{{ $r['sku_id'] }}</td>
                                    <td style="max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $r['title'] ?? '—' }}</td>
                                    <td style="font-size:11px">
                                        {{ $r['channel']->label() }}
                                        @if ($r['stock_is_provisional'])<span class="tag amber">prov</span>@endif
                                    </td>
                                    <td class="num">{{ number_format($r['soh_units']) }}</td>
                                    <td class="num">{{ $r['cover_days'] === null ? '—' : number_format($r['cover_days'], 0) }}</td>
                                    <td style="font-size:11px;color:var(--muted)">{{ $r['overstock_reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-empty title="Nothing is overstocking"
                         :note="$sellOutLoaded ? 'No SKU in view is over the cover threshold, aged, or sitting still.' : 'This list needs both a sell-out report and a stock report.'" />
            @endif
        </x-panel>

        <x-panel title="Under-supplying — stock-out risk"
                 :sub="'Under '.$limits['stockout_days'].' days of cover at the rate it is selling, or already out of stock and still selling'">
            @if ($watchlists && $watchlists['under_supplying']['all']->isNotEmpty())
                <div style="font-size:11px;color:var(--muted);margin-bottom:10px">
                    <b>{{ number_format($watchlists['under_supplying']['all']->count()) }} SKUs</b>
                    @foreach ($watchlists['under_supplying']['by_channel'] as $ch => $group)
                        · {{ \App\Enums\Channel::from($ch)->label() }} {{ $group->count() }}
                    @endforeach
                </div>
                <div class="scroll-x">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Channel</th>
                                <th class="num">Held</th>
                                <th class="num">Selling</th>
                                <th>Why it is here</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($watchlists['under_supplying']['all']->take(20) as $r)
                                <tr>
                                    <td class="mono">{{ $r['sku_id'] }}</td>
                                    <td style="max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $r['title'] ?? '—' }}</td>
                                    <td style="font-size:11px">
                                        {{ $r['channel']->label() }}
                                        @if ($r['stock_is_provisional'])<span class="tag amber">prov</span>@endif
                                    </td>
                                    <td class="num">{{ $r['soh_units'] === null ? '—' : number_format($r['soh_units']) }}</td>
                                    <td class="num">{{ number_format($r['run_rate'], 2) }}/d</td>
                                    <td style="font-size:11px;color:var(--bad)">{{ $r['stockout_reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="note" style="margin-top:10px">
                    <b>Where each run rate comes from.</b> Noon publishes its own 7-day rate and we
                    use it; DFS is a trailing average of dated orders; Amazon's sell-out report has
                    no daily detail at all, so its rate is a <em>period average</em> over the whole
                    window and is labelled as one on every row.
                </div>
            @else
                <x-empty title="Nothing is running short"
                         :note="$sellOutLoaded ? 'No SKU in view is under the cover threshold.' : 'This list needs both a sell-out report and a stock report.'" />
            @endif
        </x-panel>
    </section>

    <x-margin-lock inline>Cost, profit and margin per SKU</x-margin-lock>

    @if ($canSeeMargin)
        <div class="note">
            <b>Margin here is the revenue-weighted blend</b> across every channel a product sells on — the
            same figure, from the same engine, as the Profitability tab, never a simple average of the
            channel percentages. Per-channel detail and the Amazon / Noon / Both selector are
            <a class="link" href="{{ route('money.index', ['view' => 'sku']) }}">on Profitability → By SKU</a>.
            <b>Sell-in</b> beside it is order value — how much we sold, not what we made — and stays visible
            to everyone.
        </div>
    @endif

    <x-panel flush title="Every SKU" sub="Ordered, shipped and what is still owed — biggest sell-in first">
        <div class="scroll-x">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Brand</th>
                        <th>Category</th>
                        <th class="num">POs</th>
                        <th class="num">Accepted</th>
                        <th class="num">Shipped</th>
                        <th>Fill rate</th>
                        @if ($showValue)<th class="num">Sell-in</th>@endif
                        {{-- The M7 inline unlock: what we MAKE, beside how much we sold. --}}
                        @if ($canSeeMargin)
                            <th class="num">Cost / unit</th>
                            <th class="num">Profit / unit</th>
                            <th class="num">Margin</th>
                            <th>Verdict</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($skus as $sku)
                        <tr>
                            <td class="mono">{{ $sku['sku_id'] }}</td>
                            <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                {{ $sku['title'] }}
                            </td>
                            <td>{{ $sku['brand'] ?? '—' }}</td>
                            <td>{{ $sku['category'] ?? '—' }}</td>
                            <td class="num">{{ number_format($sku['po_count']) }}</td>
                            <td class="num">{{ number_format($sku['accepted']) }}</td>
                            <td class="num">{{ number_format($sku['shipped']) }}</td>
                            <td>
                                @if ($sku['fill_rate'] === null)
                                    <span style="color:var(--faint)">—</span>
                                @else
                                    <x-mini-bar :pct="$sku['fill_rate']" :target="$target" />
                                    {{ $sku['fill_rate'] }}%
                                @endif
                            </td>
                            @if ($showValue)
                                <td class="num">{{ Currency::plain($sku['sell_in'], $sku['currency']) }}</td>
                            @endif

                            @if ($canSeeMargin)
                                @php($m = $margins[$sku['sku_id']] ?? null)
                                <td class="num">
                                    {{ $m && $m['blend']['cogs'] !== null ? Currency::plain($m['blend']['cogs'], $m['currency']) : '—' }}
                                </td>
                                <td class="num">
                                    @if ($m && $m['blend']['profit'] !== null)
                                        <span class="mg {{ $m['blend']['profit'] >= 0 ? 'pos' : 'neg' }}">
                                            {{ Currency::plain($m['blend']['profit'], $m['currency']) }}
                                        </span>
                                    @else
                                        <span style="color:var(--faint)">—</span>
                                    @endif
                                </td>
                                <td class="num">
                                    @if ($m && $m['blend']['margin_pct'] !== null)
                                        <span class="mg {{ $m['blend']['margin_pct'] >= 0 ? 'pos' : 'neg' }}">{{ $m['blend']['margin_pct'] }}%</span>
                                    @else
                                        <span class="mg unk">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($m === null)
                                        <span class="tag" title="This SKU is not linked to a catalog product, so it has no economics">not in the catalog</span>
                                    @elseif ($m['profitable'] === null)
                                        <span class="tag" title="No selling price, so there is no margin to judge">no verdict</span>
                                    @elseif ($m['profitable'])
                                        <span class="tag good">profitable</span>
                                    @else
                                        <span class="tag bad">losing money</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="13" class="empty">No SKUs match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($catalog['linked'] < $catalog['lines'])
            <div style="padding:12px 18px 4px">
                <div class="note">
                    <b>{{ number_format($catalog['lines'] - $catalog['linked']) }} PO lines</b>
                    are not linked to a catalog product, so they show no brand or category.
                    They appear here by SKU and roll up under “not in the catalog”.
                </div>
            </div>
        @endif
    </x-panel>
</x-operon-page>
