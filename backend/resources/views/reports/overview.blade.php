@php
    use App\Services\Reporting\FulfilmentQuery;
    use App\Support\Currency;

    $cur = $totals['currency'];
    $mixed = $totals['mixed_currency'];

    // Big money reads better shortened on a tile; the exact figure is one click away.
    $short = function (float $v) {
        if (abs($v) >= 1_000_000) return [round($v / 1_000_000, 2), 'M'];
        if (abs($v) >= 1_000) return [round($v / 1_000, 1), 'k'];
        return [number_format($v, 2), ''];
    };

    [$revenueFigure, $revenueUnit] = $short($totals['shipped_value']);
    [$riskFigure, $riskUnit] = $short($totals['shortfall_value']);

    $fillStatus = FulfilmentQuery::rate($totals['fill_rate'], $benchmarks['fill_rate_target']);
    $confirmStatus = FulfilmentQuery::rate($totals['confirmation_rate'], $benchmarks['confirmation_rate_target']);
    $turnaroundTone = $averageDays === null
        ? 'n' : ($averageDays <= $benchmarks['turnaround_days'] ? 'good' : 'warn');

    $tone = fn (string $s) => match ($s) { 'good' => 'good', 'warn' => 'warn', 'bad' => 'bad', default => 'n' };

    /*
     * The two M9 tiles (§P/§R).
     *
     * Both can legitimately have no number: sell-through when no channel's sell-in and
     * sell-out cover the same days, cover when nothing is selling. In each case the tile
     * says WHICH of those it is, because "—" on its own reads as a bug.
     */
    $sellThroughContext = match (true) {
        $sellThrough === null => 'upload a sell-out report',
        $sellThrough['pct'] !== null => 'sold out ÷ received, over the same window',
        default => 'sell-out loaded, but no window lines up yet',
    };

    // The blended cover is weighted by stock, not averaged: a channel holding 60,000
    // units and one holding 200 do not get an equal say in "how long will this last".
    $coverChannels = $cover ? collect($cover['channels'])->filter(fn ($c) => $c['cover_days'] !== null) : collect();
    $coverStock = (int) $coverChannels->sum('soh_units');
    $blendedCover = $coverStock > 0
        ? round($coverChannels->sum(fn ($c) => $c['cover_days'] * $c['soh_units']) / $coverStock, 1)
        : null;

    $coverValue = $blendedCover ?? '—';
    $coverTone = match (true) {
        $blendedCover === null => 'n',
        $blendedCover >= ($cover['thresholds']['overstock_days'] ?? 90) => 'bad',
        $blendedCover < ($cover['thresholds']['stockout_days'] ?? 14) => 'bad',
        default => 'good',
    };
    $coverContext = match (true) {
        $cover === null => 'upload a stock report',
        $blendedCover === null => number_format($cover['soh_units']).' units held, nothing selling',
        default => number_format($cover['soh_units']).' units held across the channels in view',
    };
@endphp

<x-operon-page title="Overview" sub="Fulfilment health · {{ number_format($totals['po_count']) }} POs in view">
    <x-slot:controls>
        <div class="seg">
            {{-- Data-driven since M9: DFS now carries sell-out and stock of its own, and
                 a hard-coded pair silently hid a whole channel from this selector. --}}
            @foreach (['' => 'All channels'] + collect(\App\Enums\Channel::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all() as $value => $label)
                <a class="{{ (($filters->channels[0]->value ?? '') === $value && $value !== '') || ($value === '' && $filters->channels === []) ? 'on' : '' }}"
                   href="{{ route('overview.index', array_merge($filters->query(), ['channels' => $value ? [$value] : null])) }}">{{ $label }}</a>
            @endforeach
        </div>
    </x-slot:controls>

    {{-- The vitals. Six numbers the business is actually managed by (§8). --}}
    <section class="kpis">
        <x-kpi label="Fill rate"
               :value="$totals['fill_rate'] === null ? '—' : $totals['fill_rate']"
               :unit="$totals['fill_rate'] === null ? null : '%'"
               :tone="$tone($fillStatus)"
               :context="'shipped ÷ net accepted · target '.$benchmarks['fill_rate_target'].'%'"
               :href="route('fulfilment.index')" />

        <x-kpi label="Confirmation rate"
               :value="$totals['confirmation_rate'] === null ? '—' : $totals['confirmation_rate']"
               :unit="$totals['confirmation_rate'] === null ? null : '%'"
               :tone="$tone($confirmStatus)"
               :context="'accepted ÷ requested · band '.$benchmarks['confirmation_rate_target'].'–85'" />

        <x-kpi label="Avg turnaround"
               :value="$averageDays ?? '—'"
               :unit="$averageDays === null ? null : ' days'"
               :tone="$turnaroundTone"
               :chip="$averageDays !== null && $averageDays > $benchmarks['turnaround_days'] ? '▲ '.round($averageDays - $benchmarks['turnaround_days'], 1) : null"
               chipTone="w"
               :context="$averageDays === null ? 'no completed PO has a date yet' : 'against the '.$benchmarks['turnaround_days'].'-day goal'" />

        <x-kpi label="Revenue invoiced"
               :prefix="$mixed ? null : Currency::code($cur).' '"
               :value="$mixed ? 'mixed' : $revenueFigure"
               :unit="$mixed ? null : $revenueUnit"
               tone="n"
               :context="number_format($totals['shipped']).' units shipped'"
               :href="route('deliveries.index')" />

        {{-- Sell-through and days of cover (M9). Both read "—" rather than a number
             whenever the engine has no honest one to give; the panels below say why. --}}
        <x-kpi label="Sell-through"
               :value="$sellThrough['pct'] ?? '—'"
               :unit="$sellThrough && $sellThrough['pct'] !== null ? '%' : null"
               :tone="$sellThrough && $sellThrough['pct'] !== null ? 'warn' : 'n'"
               :context="$sellThroughContext"
               :href="route('products.index')" />

        <x-kpi label="Days of cover"
               :value="$coverValue"
               :unit="$coverValue === '—' ? null : ' days'"
               :tone="$coverTone"
               :chip="$cover && $cover['provisional'] ? 'DFS provisional' : null"
               chipTone="w"
               :context="$coverContext"
               :href="route('products.index')" />

        <x-kpi label="Revenue at risk"
               :prefix="$mixed ? null : Currency::code($cur).' '"
               :value="$mixed ? 'mixed' : $riskFigure"
               :unit="$mixed ? null : $riskUnit"
               :tone="$totals['shortfall_units'] > 0 ? 'bad' : 'good'"
               :context="number_format($totals['shortfall_units']).' units short · '.number_format($totals['sku_count']).' SKUs'"
               :href="route('fulfilment.index', ['view' => 'outstanding'])" />
    </section>

    <x-filters :filters="$filters" :action="route('overview.index')"
               :show="['dates', 'channels', 'fc', 'brand', 'category', 'po', 'search']" />

    <section class="row a">
        {{-- Sell-through: the "are goods actually moving" question (§8). --}}
        <x-panel title="Sell-through — are goods actually moving?"
                 sub="What we shipped to the channels vs what customers bought"
                 link="Open analysis →" :linkHref="route('products.index')">

            @if ($sellThrough)
                <div class="stbanner">
                    <div>
                        <div class="stbig">
                            @if ($sellThrough['pct'] !== null)
                                {{ $sellThrough['pct'] }}<small>%</small>
                            @else
                                —
                            @endif
                        </div>
                    </div>
                    <div class="stbar">
                        <div class="t">
                            <span>Received in — {{ $sellThrough['sell_in_units'] ? number_format($sellThrough['sell_in_units']).' units' : 'no aligned window' }}</span>
                            <span>Sold out — {{ number_format($sellThrough['sell_out_units']) }} units · {{ Currency::plain($sellThrough['sell_out_revenue'], $sellThrough['currency']) }}</span>
                        </div>
                        <div class="track">
                            @if ($sellThrough['pct'] !== null)
                                <i style="width:{{ min(100, $sellThrough['pct']) }}%"></i>
                            @endif
                        </div>
                        <div class="t" style="color:var(--faint)">
                            <span>Healthy when sell-out keeps pace with what the channel took in</span>
                            @if ($sellThrough['sitting'] > 0)
                                <span>{{ number_format($sellThrough['sitting']) }} units sitting at the channel</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Per channel, because the three answer this question very differently
                     and one blended figure would hide which is which (§1). --}}
                <div class="scroll-x" style="margin-top:14px">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>Channel</th>
                                <th class="num">Sold out</th>
                                <th class="num">Revenue</th>
                                <th class="num">Received in</th>
                                <th>Sell-through</th>
                                <th>Window</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sellThrough['channels'] as $c)
                                <tr>
                                    <td style="font-weight:650">{{ $c['channel']->label() }}</td>
                                    <td class="num">{{ number_format($c['sell_out_units']) }}</td>
                                    <td class="num">{{ Currency::plain($c['sell_out_revenue'], $c['currency']) }}</td>
                                    <td class="num">
                                        {{ $c['sell_through_denominator'] === null ? '—' : number_format($c['sell_through_denominator']) }}
                                    </td>
                                    <td>
                                        @if ($c['sell_through_pct'] !== null)
                                            <x-mini-bar :pct="min(100, $c['sell_through_pct'])" :target="100" />
                                            {{ $c['sell_through_pct'] }}%
                                        @else
                                            <span style="color:var(--faint)">not comparable</span>
                                        @endif
                                    </td>
                                    <td style="font-size:11px;color:var(--muted)">
                                        {{ $c['sell_out_days'] ? $c['sell_out_days'].' days' : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Why a channel has no percentage. This is the whole point: an absent
                     ratio with a reason is useful, an invented one is dangerous. --}}
                @foreach ($sellThrough['not_comparable'] as $c)
                    @if ($c['sell_through_note'])
                        <div class="note" style="margin-top:10px">
                            <b>{{ $c['channel']->label() }} — no sell-through figure.</b>
                            {{ $c['sell_through_note'] }}
                        </div>
                    @endif
                @endforeach
            @else
                {{-- Honest empty state. The panel keeps its shape so the screen does not
                     jump when M9 fills it, but nothing is invented in the meantime. --}}
                <div class="stbanner">
                    <div><div class="stbig">—</div></div>
                    <div class="stbar">
                        <div class="t">
                            <span>Shipped in — {{ Currency::plain($totals['shipped_value'], $cur) }}</span>
                            <span>Sold out — not loaded</span>
                        </div>
                        <div class="track"></div>
                        <div class="t" style="color:var(--faint)">
                            <span>We know what we shipped; we do not yet know what sold</span>
                        </div>
                    </div>
                </div>
                <x-empty title="No sell-out has been uploaded yet"
                         note="Sell-through compares what a channel took in against what its customers actually bought. The sell-in half is live above. Upload the Amazon sell-out report, the DFS orders or the Noon sell-out workbook and this fills in — until then it stays blank rather than showing a ratio nobody could act on." />
            @endif
        </x-panel>

        {{-- Act today: exceptions first, ranked by impact (§1). --}}
        <x-panel title="Act today" sub="Ranked by impact">
            @forelse ($alerts as $alert)
                <div class="att" @if(!$loop->first) style="margin-top:8px" @endif>
                    <div class="a {{ $alert['severity'] === 'crit' ? 'crit' : ($alert['severity'] === 'w' ? 'w' : '') }}">
                        <span class="sev {{ $alert['severity'] === 'crit' ? 'c' : ($alert['severity'] === 'w' ? 'a' : 'g') }}"></span>
                        <div class="tx">
                            <b>{{ $alert['title'] }}</b>
                            <span class="s">{{ $alert['detail'] }}</span>
                        </div>
                        <a class="go" href="{{ $alert['href'] }}">{{ $alert['action'] }}</a>
                    </div>
                </div>
            @empty
                <x-empty title="Nothing needs attention"
                         note="No shortfall, no cancellation waiting for an answer, no PO past the turnaround goal, and every feed is current." />
            @endforelse
        </x-panel>
    </section>

    {{-- Stock and how long it lasts (§P/§R, M9). --}}
    <section class="row a">
        <x-panel title="Stock and days of cover"
                 sub="What each channel is holding, and how long it lasts at the rate it is selling"
                 link="Watchlists →" :linkHref="route('products.index').'#watchlists'">
            @if ($cover)
                <div class="scroll-x">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>Channel</th>
                                <th class="num">Stock on hand</th>
                                <th class="num">Selling</th>
                                <th class="num">Days of cover</th>
                                <th class="num">Aged 90+</th>
                                <th>As at</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cover['channels'] as $c)
                                <tr>
                                    <td style="font-weight:650">
                                        {{ $c['channel']->label() }}
                                        @if ($c['stock_is_provisional'])
                                            {{-- The DFS label, carried from the data itself. --}}
                                            <span class="tag amber" title="{{ $c['stock_note'] }}">provisional</span>
                                        @endif
                                    </td>
                                    <td class="num">{{ number_format($c['soh_units']) }}</td>
                                    <td class="num">
                                        {{ $c['daily_run_rate'] === null ? '—' : number_format($c['daily_run_rate'], 1).' /day' }}
                                    </td>
                                    <td class="num">
                                        @if ($c['cover_days'] === null)
                                            <span style="color:var(--faint)">—</span>
                                        @else
                                            <span class="mg {{ $c['cover_days'] >= $cover['thresholds']['overstock_days'] || $c['cover_days'] < $cover['thresholds']['stockout_days'] ? 'neg' : 'pos' }}">
                                                {{ number_format($c['cover_days'], 1) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="num">
                                        {{ $c['aged_90_units'] === null ? '—' : number_format($c['aged_90_units']) }}
                                    </td>
                                    <td style="font-size:11px;color:var(--muted)">
                                        {{ $c['soh_as_at'] ? \Illuminate\Support\Carbon::parse($c['soh_as_at'])->format('j M Y') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div style="display:flex;gap:22px;margin-top:14px;flex-wrap:wrap">
                    <div>
                        <div style="font-size:11px;color:var(--faint)">Overstocking</div>
                        <div style="font-size:20px;font-weight:700;color:var(--bad)">{{ number_format($cover['overstocking']) }}</div>
                        <div style="font-size:11px;color:var(--muted)">{{ number_format($cover['overstocking_units']) }} units tied up</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--faint)">Under-supplying</div>
                        <div style="font-size:20px;font-weight:700;color:var(--amber-2)">{{ number_format($cover['under_supplying']) }}</div>
                        <div style="font-size:11px;color:var(--muted)">under {{ $cover['thresholds']['stockout_days'] }} days of cover</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--faint)">Aged 90+ days</div>
                        <div style="font-size:20px;font-weight:700">{{ number_format($cover['aged_90_units']) }}</div>
                        <div style="font-size:11px;color:var(--muted)">units Amazon says have not moved</div>
                    </div>
                </div>

                @if ($cover['provisional'])
                    <div class="note warn" style="margin-top:12px">
                        <b>DFS stock is provisional.</b> It is Amazon's view of our direct-fulfilment
                        stock rather than our own warehouse system, so DFS days of cover is an
                        indication only. The real position arrives with the in-house-tool link.
                    </div>
                @endif
            @else
                <x-empty title="No stock report has been uploaded yet"
                         note="Days of cover is stock on hand divided by how fast it is selling. The selling half is live; upload the Amazon inventory report, the DFS inventory CSV or the Noon workbook and this fills in." />
            @endif
        </x-panel>

        {{-- Fulfilment centres: where the volume is and how well each one is served. --}}
        <x-panel title="Fulfilment centres" sub="PO value and fill rate by FC"
                 link="All deliveries →" :linkHref="route('deliveries.index')">
            @if ($fcs->isNotEmpty())
                @php $peak = max(1, $fcs->max('value')); @endphp

                <div class="bars">
                    @foreach ($fcs->take(7) as $fc)
                        <div class="bar {{ $fc['rush'] ? 'rush' : '' }}">
                            <div class="fill" style="height:{{ max(3, round($fc['value'] / $peak * 100)) }}%"></div>
                            <div class="val">{{ $fc['value'] >= 1000 ? round($fc['value'] / 1000).'k' : round($fc['value']) }}</div>
                            <div class="lbl">{{ $fc['fc'] }}@if($fc['rush'])<span class="rt">RUSH</span>@endif</div>
                        </div>
                    @endforeach
                </div>

                <div class="scroll-x">
                    <table class="tbl mt">
                        <thead>
                            <tr>
                                <th>Fulfilment centre</th>
                                <th class="num">POs</th>
                                <th class="num">Ordered</th>
                                <th class="num">Shipped</th>
                                <th class="num">PO value</th>
                                <th>Fill rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fcs as $fc)
                                <tr>
                                    <td><span class="fc">@if($fc['rush'])<span class="rt">RUSH</span>@endif{{ $fc['fc'] }}</span></td>
                                    <td class="num">{{ number_format($fc['po_count']) }}</td>
                                    <td class="num">{{ number_format($fc['accepted']) }}</td>
                                    <td class="num">{{ number_format($fc['shipped']) }}</td>
                                    <td class="num">{{ Currency::plain($fc['value'], $fc['currency']) }}</td>
                                    <td>
                                        @if ($fc['fill_rate'] === null)
                                            <span style="color:var(--faint)">—</span>
                                        @else
                                            <x-mini-bar :pct="$fc['fill_rate']" :target="$benchmarks['fill_rate_target']" />
                                            {{ $fc['fill_rate'] }}%
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-empty title="No fulfilment centres in view"
                         note="Either no POs match these filters, or the PO export has not been uploaded yet." />
            @endif
        </x-panel>

        <x-panel title="Channel mix" sub="Shipped revenue by channel">
            <div class="ch">
                @foreach ($channels as $channel)
                    <div class="c">
                        <div class="badge" style="background:{{ $channel['badge'][1] }};color:{{ $channel['badge'][2] }}">
                            {{ $channel['badge'][0] }}
                        </div>
                        <div style="flex:1;min-width:0">
                            <div class="nm">{{ $channel['channel']->label() }}</div>
                            <div class="mt">
                                @if ($channel['loaded'])
                                    {{ number_format($channel['po_count']) }} POs
                                    @if ($channel['fill_rate'] !== null) · {{ $channel['fill_rate'] }}% fill @endif
                                    @if ($channel['confirmation_rate'] !== null) · {{ $channel['confirmation_rate'] }}% confirm @endif
                                @else
                                    not ingested yet
                                @endif
                            </div>
                        </div>
                        <div class="amt">
                            @if ($channel['loaded'])
                                {{ Currency::plain($channel['value'], $channel['currency']) }}
                            @else
                                <span style="color:var(--faint);font-weight:600">—</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Why a blended fill rate reads lower than the completed one (§8). --}}
            <div class="ph" style="margin:18px 0 0">
                <div>
                    <h2 style="font-size:12.5px">In-flight vs complete</h2>
                    <div class="sub">why blended fill looks lower</div>
                </div>
            </div>
            <div class="ch" style="margin-top:12px">
                <div class="c">
                    <div style="flex:1">
                        <div class="nm">Completed POs</div>
                        <div class="mt">shipped &amp; closed</div>
                    </div>
                    <div class="amt" style="color:var(--good)">
                        {{ $inFlight['completed_fill'] === null ? $inFlight['completed'].' POs' : $inFlight['completed_fill'].'% fill' }}
                    </div>
                </div>
                <div class="c">
                    <div style="flex:1">
                        <div class="nm">In-flight POs</div>
                        <div class="mt">deliveries still to upload</div>
                    </div>
                    <div class="amt" style="color:var(--muted)">{{ number_format($inFlight['open']) }} open</div>
                </div>
            </div>

            @if ($coverage['lines_total'] > 0 && $coverage['lines_linked'] < $coverage['lines_total'])
                <div class="note" style="margin-top:14px">
                    <b>{{ number_format($coverage['lines_total'] - $coverage['lines_linked']) }} PO lines</b>
                    are not in the master catalog yet, so they group under
                    “not in the catalog” on brand and category breakdowns.
                </div>
            @endif
        </x-panel>
    </section>
</x-operon-page>
