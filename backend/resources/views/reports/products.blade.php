@php
    use App\Support\Currency;

    $showValue = auth()->user()->canSeeOrderValue();
    $cur = $totals['currency'];
    $target = $benchmarks['fill_rate_target'];
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

        <x-kpi label="Sell-through" value="—" tone="n"
               :context="$sellOutLoaded ? 'sell-out loaded' : 'needs the sell-out report (M9)'" />

        <x-kpi label="Not shipped" :value="number_format($totals['shortfall_units'])" unit=" units"
               :tone="$totals['shortfall_units'] > 0 ? 'bad' : 'good'"
               :context="$showValue ? Currency::plain($totals['shortfall_value'], $cur).' of accepted units' : 'accepted but not shipped'" />
    </section>

    <x-filters :filters="$filters" :action="route('products.index')"
               :exportHref="route('products.index', array_merge($filters->query(), ['export' => 'csv']))"
               :show="['dates', 'channels', 'fc', 'brand', 'category', 'search', 'skus']" />

    @unless ($sellOutLoaded)
        <div class="note warn">
            <b>Sell-out is not ingested yet.</b> Everything here measures <b>sell-in</b> — what we
            shipped to the channels. Sell-through, ABC/XYZ and the sell-in-vs-sell-out quadrant
            need the Amazon sell-out report, which arrives at M9. Rather than leave the space
            empty, the quadrant below plots what we can measure today: how much of each SKU the
            channel orders against how reliably we fill it.
        </div>
    @endunless

    <section class="row a">
        {{-- The labelled quadrant (§1: no chart a teammate has to decode). --}}
        <x-panel title="Volume against fill rate"
                 sub="Each dot is one SKU. Top-right is high volume filled well; bottom-right is high volume we keep missing.">
            @if ($quadrant['points']->isNotEmpty())
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

        {{-- Sell-in vs sell-out, honestly half-empty until M9. --}}
        <x-panel title="Sell-in vs sell-out"
                 sub="What we shipped to the channels against what their customers bought">
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

            <x-empty title="The sell-out half arrives at M9"
                     note="Sell-through, days of cover, the overstocking and under-supplying watchlists and the ABC/XYZ split all need it. Everything on this page that does not depend on it is live now." />
        </x-panel>
    </section>

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
                        </tr>
                    @empty
                        <tr><td colspan="9" class="empty">No SKUs match these filters.</td></tr>
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
