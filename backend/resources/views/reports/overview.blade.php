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
@endphp

<x-operon-page title="Overview" sub="Fulfilment health · {{ number_format($totals['po_count']) }} POs in view">
    <x-slot:controls>
        <div class="seg">
            @foreach (['' => 'All channels', 'amazon_retail' => 'Amazon', 'noon_retail' => 'Noon'] as $value => $label)
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
               :href="route('shipments.index')" />

        <x-kpi label="Sell-through"
               :value="$sellThrough['pct'] ?? '—'"
               :unit="$sellThrough ? '%' : null"
               :tone="$sellThrough ? 'warn' : 'n'"
               :context="$sellThrough ? 'shipped in vs sold out' : 'needs the sell-out report (M9)'" />

        <x-kpi label="Revenue at risk"
               :prefix="$mixed ? null : Currency::code($cur).' '"
               :value="$mixed ? 'mixed' : $riskFigure"
               :unit="$mixed ? null : $riskUnit"
               :tone="$totals['shortfall_units'] > 0 ? 'bad' : 'good'"
               :context="number_format($totals['shortfall_units']).' units short · '.number_format($totals['sku_count']).' SKUs'"
               :href="route('pending.index')" />
    </section>

    <x-filters :filters="$filters" :action="route('overview.index')"
               :show="['dates', 'channels', 'fc', 'brand', 'category', 'po', 'search']" />

    <section class="row a">
        {{-- Sell-through: the "are goods actually moving" question (§8). --}}
        <x-panel title="Sell-through — are goods actually moving?"
                 sub="What we shipped to the channels vs what customers bought">

            @if ($sellThrough)
                <div class="stbanner">
                    <div><div class="stbig">{{ $sellThrough['pct'] }}<small>%</small></div></div>
                    <div class="stbar">
                        <div class="t">
                            <span>Shipped in — {{ Currency::plain($sellThrough['sell_in'], $cur) }}</span>
                            <span>Sold out — {{ Currency::plain($sellThrough['sell_out'], $cur) }}</span>
                        </div>
                        <div class="track"><i style="width:{{ min(100, $sellThrough['pct']) }}%"></i></div>
                        <div class="t" style="color:var(--faint)">
                            <span>Healthy when sell-out keeps pace with sell-in</span>
                            <span>{{ Currency::plain($sellThrough['sitting'], $cur) }} sitting at the channel</span>
                        </div>
                    </div>
                </div>
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
                <x-empty title="Sell-out is not ingested yet"
                         note="Sell-through compares what we shipped to a channel against what its customers actually bought. The sell-in half is live above. The sell-out half comes from the Amazon sell-out report, which arrives at M9 — until then this stays blank rather than showing a ratio nobody could act on." />
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

    <section class="row a">
        {{-- Fulfilment centres: where the volume is and how well each one is served. --}}
        <x-panel title="Fulfilment centres" sub="PO value and fill rate by FC"
                 link="All deliveries →" :linkHref="route('shipments.index')">
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
