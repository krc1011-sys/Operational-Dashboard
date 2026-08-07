<?php

namespace App\Services\Analytics;

use App\Enums\Channel;
use App\Enums\Stage;
use App\Models\InventorySnapshot;
use App\Models\PoLine;
use App\Models\SelloutRow;
use App\Models\ShipmentLine;
use App\Services\Reporting\FilterSet;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sell-in against sell-out, velocity, days of cover and the watchlists (M9, §P/§R).
 *
 * ═══ THE FOUR NUMBERS, AND WHAT EACH ONE IS ═══
 *
 *   SELL-IN     what WE shipped INTO the channel. Amazon Retail and Noon: the shipped
 *               quantity on their PO lines, which is the reconciled figure the fill-rate
 *               engine already produced. DFS has no PO at all, so it has no sell-in.
 *   SELL-OUT    what the channel's customers bought. One table, three channels.
 *   SELL-THROUGH  sell-out ÷ sell-in. Below 100% means stock is piling up at the channel,
 *               which throttles future POs and ties up cash on ~60-day terms.
 *   COVER       stock on hand ÷ daily run rate — how long what they hold will last.
 *
 * ═══ THREE THINGS THIS CLASS REFUSES TO DO ═══
 *
 * 1. IT DOES NOT COMPUTE SELL-THROUGH FOR DFS. On Direct Fulfilment the order line IS
 *    the sale: there is no sell-in step, so the ratio would be 100% by construction and
 *    would mean nothing. DFS reports units and revenue and leaves sell-through null, and
 *    the screens print the reason.
 *
 * 2. IT DOES NOT COMPARE PERIODS IT CANNOT ALIGN. Amazon's sell-out window (66 days) and
 *    the POs we shipped are different spans, so the ratio is reported WITH the window it
 *    was measured over rather than as a bare percentage.
 *
 * 3. IT DOES NOT INVENT A RUN RATE. Where a channel gives no basis, the rate is null and
 *    cover is null — never zero, never a large sentinel. See RunRate.
 *
 * Nothing here writes a row or defines an economic rule; it reads what the importers
 * stored and what the fill-rate engine already cached.
 */
class SellThroughEngine
{
    public function __construct(private readonly FilterSet $filters) {}

    // --- Channel level ----------------------------------------------------

    /**
     * One row per channel: sell-in, sell-out, sell-through, stock and cover.
     *
     * Every Phase-1 channel appears even with no data, because "Noon: not loaded" is
     * information and a missing row is not.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byChannel(): Collection
    {
        $sellOut = $this->sellOutTotals();
        $stock = $this->stockTotals();
        $sellIn = $this->sellInTotals();

        return collect($this->filters->activeChannels())->map(function (Channel $channel) use ($sellOut, $stock, $sellIn) {
            $out = $sellOut->get($channel->value);
            $held = $stock->get($channel->value);
            $in = $sellIn->get($channel->value);

            $outUnits = (int) ($out['units'] ?? 0);
            $inUnits = (int) ($in['units'] ?? 0);
            $sohUnits = $held['soh_units'] ?? null;

            // The denominator, chosen and explained — see sellThroughFor().
            $through = $this->sellThroughFor($channel, $outUnits, $out, $held);

            /*
             * The channel-level run rate is the whole channel's sell-out over the days it
             * covers. It is not the average of the per-SKU rates - those have different
             * windows and different bases, and averaging them would produce a number with
             * no unit at all.
             */
            $days = $out['days'] ?? null;
            $rate = ($days === null || $outUnits === 0)
                ? null
                : round($outUnits / max(1, $days), 2);

            return [
                'channel' => $channel,
                'loaded' => $out !== null || $held !== null,

                'sell_in_units' => $channel->hasPurchaseOrders() ? $inUnits : null,
                'sell_in_value' => $channel->hasPurchaseOrders() ? (float) ($in['value'] ?? 0) : null,

                'sell_out_units' => $outUnits,
                'sell_out_revenue' => (float) ($out['revenue'] ?? 0),
                'sell_out_from' => $out['from'] ?? null,
                'sell_out_to' => $out['to'] ?? null,
                'sell_out_days' => $days,
                'sell_out_grain' => $out['grain'] ?? null,

                // Null for DFS on purpose, and null whenever the two windows do not line
                // up - see sellThroughFor(). Never a plausible-looking guess.
                'sell_through_pct' => $through['pct'],
                'sell_through_basis' => $through['basis'],
                'sell_through_note' => $through['note'],
                'sell_through_denominator' => $through['denominator'],
                'sell_in_window_units' => $through['window_units'],
                'sell_in_window_days' => $through['window_days'],
                'sitting_units' => $through['sitting'],

                'soh_units' => $sohUnits,
                'soh_as_at' => $held['as_at'] ?? null,
                'aged_90_units' => $held['aged_90_units'] ?? null,
                'open_po_units' => $held['open_po_units'] ?? null,
                // Amazon's own count of what it received - an independent check on sell-in.
                'net_received_units' => $held['net_received_units'] ?? null,
                'stock_is_provisional' => (bool) ($held['is_provisional'] ?? false),
                'stock_note' => $held['provisional_note'] ?? null,

                'daily_run_rate' => $rate,
                'cover_days' => ($rate === null || $rate <= 0 || $sohUnits === null)
                    ? null : round($sohUnits / $rate, 1),

                'currency' => Currency::code($out['currency'] ?? $held['currency'] ?? null),
            ];
        });
    }

    /** Is there any sell-out at all in view? Screens ask before drawing an empty panel. */
    public function hasSellOut(): bool
    {
        return $this->filters->applyToSellout(SelloutRow::query())->exists();
    }

    public function hasStock(): bool
    {
        return $this->filters->applyToInventory(InventorySnapshot::query())->exists();
    }

    // --- SKU level --------------------------------------------------------

    /**
     * One row per (channel, SKU): sell-out, velocity, stock and cover.
     *
     * This is what the watchlists and the quadrant are both built from, so it is
     * computed once and handed to both rather than each running its own query.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function skuRows(int $limit = 4000): Collection
    {
        $stock = $this->stockBySku();
        $sellIn = $this->sellInBySku();
        $recent = $this->recentWindows();
        // How many days of sell-out we hold per channel. Needed by every row, including
        // the ones with NO sell-out - see buildRow().
        $windows = $this->sellOutTotals()->map(fn (array $t) => $t['days']);

        $rows = $this->filters->applyToSellout(SelloutRow::query())
            ->selectRaw('
                channel,
                sku_id,
                MAX(sku_id_type) as sku_id_type,
                MAX(grain) as grain,
                MAX(product_id) as product_id,
                MAX(title) as title,
                MAX(barcode) as barcode,
                COALESCE(SUM(shipped_units), 0) as units,
                COALESCE(SUM(revenue), 0) as revenue,
                COALESCE(SUM(customer_returns), 0) as returns,
                MIN(period_start) as period_start,
                MAX(period_end) as period_end,
                COUNT(*) as row_count,
                MAX(currency) as currency
            ')
            ->groupBy('channel', 'sku_id')
            ->orderByDesc('units')
            ->limit($limit)
            ->get();

        /*
         * Every SKU with sell-out is above. A SKU with STOCK and NO SELL-OUT never
         * appears in that query, and it is the single most important row on an overstock
         * screen - so the two sets are unioned rather than joined.
         */
        $seen = $rows->map(fn ($r) => $this->key($r->channel, $r->sku_id))->flip();

        $built = $rows->map(fn ($row) => $this->buildRow(
            channel: $row->channel instanceof Channel ? $row->channel : Channel::from($row->channel),
            skuId: $row->sku_id,
            skuIdType: $row->sku_id_type,
            grain: $row->grain,
            productId: $row->product_id === null ? null : (int) $row->product_id,
            title: $row->title,
            barcode: $row->barcode,
            units: (int) $row->units,
            revenue: (float) $row->revenue,
            returns: (int) $row->returns,
            from: $row->period_start,
            to: $row->period_end,
            currency: $row->currency,
            stock: $stock,
            sellIn: $sellIn,
            recent: $recent,
            windows: $windows,
        ));

        $stockOnly = $stock
            ->reject(fn ($s, $key) => $seen->has($key))
            ->map(fn ($s) => $this->buildRow(
                channel: $s['channel'],
                skuId: $s['sku_id'],
                skuIdType: $s['sku_id_type'],
                grain: null,
                productId: $s['product_id'],
                title: $s['title'],
                barcode: $s['barcode'],
                units: 0,
                revenue: 0.0,
                returns: 0,
                from: null,
                to: null,
                currency: $s['currency'],
                stock: $stock,
                sellIn: $sellIn,
                recent: $recent,
                windows: $windows,
            ))
            ->values();

        return $built->concat($stockOnly)->values();
    }

    /**
     * The two watchlists §D asks for, per channel and combined.
     *
     * OVERSTOCKING is deliberately reachable three ways, because a SKU can be
     * overstocked in three different shapes and a single rule would miss two of them:
     *
     *   cover      more days of stock than the threshold — the classic case
     *   aged       Amazon SAYS it has sat 90 days — no arithmetic, no assumption
     *   dead       stock on hand that sold NOTHING across the window. It has no run
     *              rate, so a cover rule can never see it, and it is usually the worst
     *              of the three.
     *
     * UNDER-SUPPLYING is cover below the threshold on a SKU that is genuinely moving,
     * plus the sharper case of a SKU with a real run rate and NO stock left.
     *
     * @return array<string, mixed>
     */
    public function watchlists(?Collection $rows = null): array
    {
        $rows ??= $this->skuRows();
        $thresholds = config('operon.cover');

        $overstock = $rows
            ->filter(fn (array $r) => $r['overstock_reason'] !== null)
            ->sortByDesc(fn (array $r) => $r['soh_units'] ?? 0)
            ->values();

        $under = $rows
            ->filter(fn (array $r) => $r['stockout_reason'] !== null)
            // Worst first: nothing left and still selling beats "a few days left".
            ->sortBy(fn (array $r) => [$r['cover_days'] ?? -1, -($r['run_rate'] ?? 0)])
            ->values();

        return [
            'thresholds' => $thresholds,
            'overstocking' => [
                'all' => $overstock,
                'by_channel' => $overstock->groupBy(fn (array $r) => $r['channel']->value),
                'units' => (int) $overstock->sum(fn (array $r) => $r['soh_units'] ?? 0),
            ],
            'under_supplying' => [
                'all' => $under,
                'by_channel' => $under->groupBy(fn (array $r) => $r['channel']->value),
                'units' => (int) $under->sum(fn (array $r) => $r['soh_units'] ?? 0),
            ],
        ];
    }

    // --- Building one row -------------------------------------------------

    private function buildRow(
        Channel $channel,
        string $skuId,
        ?string $skuIdType,
        ?string $grain,
        ?int $productId,
        ?string $title,
        ?string $barcode,
        int $units,
        float $revenue,
        int $returns,
        $from,
        $to,
        ?string $currency,
        Collection $stock,
        Collection $sellIn,
        array $recent,
        Collection $windows,
    ): array {
        $key = $this->key($channel->value, $skuId);
        $held = $stock->get($key);
        $shippedIn = $sellIn->get($key);

        // The days of sell-out this CHANNEL covers, whether or not this SKU appears in it.
        $channelDays = $windows->get($channel->value);

        $rate = $this->runRateFor($channel, $key, $units, $grain, $from, $to, $held, $recent, $channelDays);

        $soh = $held['soh_units'] ?? null;
        $aged = $held['aged_90_units'] ?? null;
        $cover = $rate->coverDays($soh);

        $sellInUnits = $channel->hasPurchaseOrders() ? (int) ($shippedIn['units'] ?? 0) : null;

        $row = [
            'channel' => $channel,
            'sku_id' => $skuId,
            'sku_id_type' => $skuIdType,
            'product_id' => $productId ?? ($held['product_id'] ?? null),
            'title' => $title ?? ($held['title'] ?? null),
            'brand' => $held['brand'] ?? null,
            'barcode' => $barcode ?? ($held['barcode'] ?? null),

            'sell_out_units' => $units,
            'sell_out_revenue' => round($revenue, 2),
            'customer_returns' => $returns,
            'sell_out_from' => $from,
            'sell_out_to' => $to,
            'sell_out_window_days' => $channelDays,

            'run_rate' => $rate->perDay,
            'run_rate_basis' => $rate->basis,
            'run_rate_window_days' => $rate->windowDays,
            'run_rate_is_period_average' => $rate->isPeriodAverage,
            'run_rate_is_stated' => $rate->isStated,

            'soh_units' => $soh,
            'aged_90_units' => $aged,
            'open_po_units' => $held['open_po_units'] ?? null,
            'stock_is_provisional' => (bool) ($held['is_provisional'] ?? false),
            'stock_note' => $held['provisional_note'] ?? null,
            'stock_as_at' => $held['snapshot_date'] ?? null,

            'cover_days' => $cover,

            'sell_in_units' => $sellInUnits,
            'sell_in_value' => $channel->hasPurchaseOrders() ? (float) ($shippedIn['value'] ?? 0) : null,
            'net_received_units' => $held['net_received_units'] ?? null,

            /*
             * PER-SKU SELL-THROUGH USES THE SAME RULE AS THE CHANNEL TILE, for the same
             * reason: it is reported only against a denominator covering the same days.
             * Amazon's inventory report gives one per ASIN — "Net Received Units" — so
             * Amazon SKUs get a real figure. Elsewhere the row still carries both unit
             * counts; it simply does not divide two spans that do not line up.
             */
            'sell_through_pct' => $this->skuSellThrough($channel, $units, $held),
            'sell_through_basis' => ($held['net_received_units'] ?? 0) > 0
                ? "the channel's own received units for this window"
                : null,

            'currency' => Currency::code($currency ?? ($held['currency'] ?? null)),
        ];

        return $row + $this->classify($row, $rate);
    }

    /**
     * Which watchlist, if any, this row belongs on — and the sentence that says why.
     *
     * The reason is part of the answer, not decoration: "132 days of cover" and "Amazon
     * says this has sat 90 days" and "1,400 units that sold nothing in two months" call
     * for three different conversations, and a bare list would flatten them into one.
     *
     * @return array{overstock_reason: ?string, stockout_reason: ?string}
     */
    private function classify(array $row, RunRate $rate): array
    {
        $limits = config('operon.cover');
        $soh = $row['soh_units'];
        $minUnits = (int) $limits['min_units_to_flag'];

        $overstock = null;
        $stockout = null;

        if ($soh !== null && $soh >= $minUnits) {
            /*
             * Dead stock is judged against the CHANNEL's window, not the run rate's own.
             * Noon's L7_DRR has a 7-day window, so a Noon SKU sitting at a stated rate of
             * zero would never clear a 30-day dead-stock bar on its rate's window - even
             * though the workbook plainly shows it selling nothing for 61 days.
             */
            $windowDays = (int) ($row['sell_out_window_days'] ?? $rate->windowDays ?? 0);

            if ($row['cover_days'] !== null && $row['cover_days'] >= $limits['overstock_days']) {
                $overstock = sprintf('%s days of cover on %s units',
                    number_format($row['cover_days'], 0), number_format($soh));
            } elseif (($row['aged_90_units'] ?? 0) > 0) {
                // Amazon's own statement, which needs no run rate to be true.
                $overstock = sprintf('%s units aged 90+ days, per Amazon',
                    number_format($row['aged_90_units']));
            } elseif ($row['sell_out_units'] <= 0 && $windowDays >= $limits['dead_stock_days']) {
                $overstock = sprintf('%s units, nothing sold in %d days',
                    number_format($soh), $windowDays);
            }
        }

        if ($rate->isKnown() && $rate->perDay > 0) {
            if ($soh !== null && $soh <= 0) {
                $stockout = sprintf('out of stock, still selling %s a day',
                    rtrim(rtrim(number_format($rate->perDay, 2), '0'), '.'));
            } elseif ($row['cover_days'] !== null && $row['cover_days'] < $limits['stockout_days']) {
                $stockout = sprintf('%s days of cover left at %s a day',
                    number_format($row['cover_days'], 1),
                    rtrim(rtrim(number_format($rate->perDay, 2), '0'), '.'));
            }
        }

        return ['overstock_reason' => $overstock, 'stockout_reason' => $stockout];
    }

    /**
     * The run rate for one SKU on one channel — the heart of M9.
     *
     * Each branch is a different QUALITY of answer, and RunRate carries which one it is
     * all the way to the screen. See that class for why that matters.
     */
    private function runRateFor(
        Channel $channel,
        string $key,
        int $units,
        ?string $grain,
        $from,
        $to,
        ?array $held,
        array $recent,
        ?int $channelDays,
    ): RunRate {
        // 1. The channel told us. Nothing we derive can improve on it.
        $stated = $held['daily_run_rate'] ?? null;

        if ($stated !== null) {
            return RunRate::stated((float) $stated, "Noon's own L7 daily run rate", 7);
        }

        // 2. Daily rows: a real trailing average.
        if ($grain === SelloutRow::GRAIN_DAY) {
            $l7 = (int) ($recent['l7'][$key] ?? 0);
            $l30 = (int) ($recent['l30'][$key] ?? 0);

            // A week of zeroes on a slow SKU is noise, not a stop — fall back to 30 days.
            return $l7 > 0
                ? RunRate::derived($l7, 7, 'last 7 days of orders')
                : RunRate::derived($l30, 30, 'last 30 days of orders');
        }

        // 3. An aggregated window and nothing finer. A period average, labelled.
        if ($from !== null && $to !== null) {
            $days = $this->daysBetween($from, $to);

            return RunRate::periodAverage($units, $days,
                sprintf('period average over %d days — the report carries no daily detail', $days));
        }

        /*
         * 4. Stock, and this SKU is NOT IN THE SELL-OUT REPORT AT ALL.
         *
         * That absence is not missing data, it is a fact: the report covers those days
         * and this SKU is not in it, so it sold nothing. Saying "unknown" here would be
         * the expensive mistake - 243 Amazon ASINs are in this state, holding stock that
         * has not moved in 66 days, and a null run rate would keep every one of them off
         * the overstock list. They are the worst overstock we have.
         */
        if ($channelDays !== null && $channelDays > 0) {
            return RunRate::derived(0, $channelDays,
                sprintf('nothing sold in the %d days the report covers', $channelDays));
        }

        return RunRate::unknown('stock on hand, and no sell-out uploaded for this channel');
    }

    // --- The aggregates the rows are built from ---------------------------

    /** @return Collection<string, array<string, mixed>> keyed by channel */
    private function sellOutTotals(): Collection
    {
        return $this->filters->applyToSellout(SelloutRow::query())
            ->selectRaw('
                channel,
                MAX(grain) as grain,
                COALESCE(SUM(shipped_units), 0) as units,
                COALESCE(SUM(revenue), 0) as revenue,
                MIN(period_start) as period_start,
                MAX(period_end) as period_end,
                MAX(currency) as currency
            ')
            ->groupBy('channel')
            ->get()
            ->mapWithKeys(fn ($row) => [
                ($row->channel instanceof Channel ? $row->channel->value : $row->channel) => [
                    'units' => (int) $row->units,
                    'revenue' => (float) $row->revenue,
                    'from' => $row->period_start,
                    'to' => $row->period_end,
                    'days' => $this->daysBetween($row->period_start, $row->period_end),
                    'grain' => $row->grain,
                    'currency' => $row->currency,
                ],
            ]);
    }

    /** @return Collection<string, array<string, mixed>> keyed by channel */
    private function stockTotals(): Collection
    {
        return collect(Channel::cases())
            ->mapWithKeys(function (Channel $channel) {
                $date = InventorySnapshot::latestDateFor($channel);

                if ($date === null) {
                    return [];
                }

                $row = $this->filters->applyToInventory(InventorySnapshot::query())
                    ->where('channel', $channel->value)
                    ->whereDate('snapshot_date', $date)
                    ->selectRaw('
                        COALESCE(SUM(soh_units), 0) as soh_units,
                        SUM(aged_90_units) as aged_90_units,
                        SUM(open_po_units) as open_po_units,
                        SUM(net_received_units) as net_received_units,
                        MAX(is_provisional) as is_provisional,
                        MAX(provisional_note) as provisional_note,
                        MAX(currency) as currency,
                        COUNT(*) as sku_count
                    ')
                    ->first();

                if ($row === null || (int) $row->sku_count === 0) {
                    return [];
                }

                return [$channel->value => [
                    'soh_units' => (int) $row->soh_units,
                    'aged_90_units' => $row->aged_90_units === null ? null : (int) $row->aged_90_units,
                    'open_po_units' => $row->open_po_units === null ? null : (int) $row->open_po_units,
                    'net_received_units' => $row->net_received_units === null ? null : (int) $row->net_received_units,
                    'is_provisional' => (bool) $row->is_provisional,
                    'provisional_note' => $row->provisional_note,
                    'currency' => $row->currency,
                    'as_at' => $date,
                ]];
            });
    }

    /**
     * Sell-in: what we shipped INTO each channel, from the reconciled PO lines.
     *
     * `qty_shipped` rather than `qty_accepted` deliberately — sell-in is what actually
     * went, not what was promised. DFS is absent because it has no PO lines at all.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function sellInTotals(): Collection
    {
        return $this->filters->applyToLines(PoLine::query())
            ->selectRaw('
                channel,
                COALESCE(SUM(qty_shipped), 0) as units,
                COALESCE(SUM(qty_shipped * unit_cost), 0) as value
            ')
            ->groupBy('channel')
            ->get()
            ->mapWithKeys(fn ($row) => [
                ($row->channel instanceof Channel ? $row->channel->value : $row->channel) => [
                    'units' => (int) $row->units,
                    'value' => (float) $row->value,
                ],
            ]);
    }

    /** @return Collection<string, array<string, mixed>> keyed by "channel|sku" */
    private function sellInBySku(): Collection
    {
        return $this->filters->applyToLines(PoLine::query())
            ->selectRaw('
                channel, sku_id,
                COALESCE(SUM(qty_shipped), 0) as units,
                COALESCE(SUM(qty_shipped * unit_cost), 0) as value
            ')
            ->groupBy('channel', 'sku_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->key($row->channel instanceof Channel ? $row->channel->value : $row->channel, $row->sku_id) => [
                    'units' => (int) $row->units,
                    'value' => (float) $row->value,
                ],
            ]);
    }

    /**
     * The latest stock snapshot for every SKU on every channel.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function stockBySku(): Collection
    {
        $dates = collect(Channel::cases())
            ->mapWithKeys(fn (Channel $c) => [$c->value => InventorySnapshot::latestDateFor($c)])
            ->filter();

        if ($dates->isEmpty()) {
            return collect();
        }

        $query = $this->filters->applyToInventory(InventorySnapshot::query());

        $query->where(function ($q) use ($dates) {
            foreach ($dates as $channel => $date) {
                $q->orWhere(fn ($inner) => $inner
                    ->where('channel', $channel)
                    ->whereDate('snapshot_date', $date));
            }
        });

        return $query->get()->mapWithKeys(fn (InventorySnapshot $s) => [
            $this->key($s->channel->value, $s->sku_id) => [
                'channel' => $s->channel,
                'sku_id' => $s->sku_id,
                'sku_id_type' => $s->sku_id_type,
                'product_id' => $s->product_id,
                'title' => $s->title,
                'brand' => $s->brand,
                'barcode' => $s->barcode,
                'soh_units' => $s->soh_units === null ? null : (int) $s->soh_units,
                'aged_90_units' => $s->aged_90_units === null ? null : (int) $s->aged_90_units,
                'open_po_units' => $s->open_po_units === null ? null : (int) $s->open_po_units,
                'net_received_units' => $s->net_received_units === null ? null : (int) $s->net_received_units,
                'daily_run_rate' => $s->daily_run_rate === null ? null : (float) $s->daily_run_rate,
                'is_provisional' => $s->is_provisional,
                'provisional_note' => $s->provisional_note,
                'snapshot_date' => $s->snapshot_date,
                'currency' => $s->currency,
            ],
        ]);
    }

    /**
     * Units sold in the last 7 and 30 days, for channels with daily rows.
     *
     * ANCHORED ON THE DATA, NOT ON TODAY. The DFS extract ends on 4 Aug and may be read
     * on the 7th; counting back from today would silently score three days of zeroes
     * against every SKU and cut every DFS run rate by a third — a wrong answer that
     * looks entirely reasonable.
     *
     * @return array{l7: array<string,int>, l30: array<string,int>}
     */
    private function recentWindows(): array
    {
        $out = ['l7' => [], 'l30' => []];

        foreach ([Channel::AmazonDfs, Channel::NoonRetail] as $channel) {
            $latest = $this->filters->applyToSellout(SelloutRow::query())
                ->where('channel', $channel->value)
                ->daily()
                ->max('period_end');

            if ($latest === null) {
                continue;
            }

            $latest = Carbon::parse($latest);

            foreach (['l7' => 7, 'l30' => 30] as $label => $days) {
                // Inclusive of the last day: 7 days means the last day and the 6 before it.
                $from = $latest->copy()->subDays($days - 1);

                $rows = $this->filters->applyToSellout(SelloutRow::query())
                    ->where('channel', $channel->value)
                    ->daily()
                    ->whereDate('period_start', '>=', $from)
                    ->selectRaw('sku_id, COALESCE(SUM(shipped_units), 0) as units')
                    ->groupBy('sku_id')
                    ->get();

                foreach ($rows as $row) {
                    $out[$label][$this->key($channel->value, $row->sku_id)] = (int) $row->units;
                }
            }
        }

        return $out;
    }

    // --- Small shared rules -----------------------------------------------

    /**
     * Sell-through, WITH THE DENOMINATOR IT WAS MEASURED AGAINST — or null, with a reason.
     *
     * ╔══════════════════════════════════════════════════════════════════════════════╗
     * ║  A SELL-THROUGH FIGURE IS ONLY MEANINGFUL IF ITS TWO HALVES COVER THE SAME   ║
     * ║  DAYS. This method exists because on the real files they mostly do not.      ║
     * ╚══════════════════════════════════════════════════════════════════════════════╝
     *
     * The Amazon sell-out report covers 1 Jun – 5 Aug. Nine of the eleven Amazon
     * deliveries we hold are dated 15–20 Aug — AFTER the window closes. Dividing one by
     * the other gives 598%, which is not a channel selling six times what we sent it, it
     * is two unrelated spans being compared. That number would sit on the Overview tile
     * looking like a triumph.
     *
     * So the denominator is chosen in this order:
     *
     *  1. THE CHANNEL'S OWN RECEIVED COUNT. Amazon's inventory report carries "Net
     *     Received Units" for exactly the window the sell-out report covers — 127,114
     *     units against 84,434 sold, a sell-through of 66.4%. It is aligned by
     *     construction and it is Amazon's own count, so nothing beats it.
     *
     *  2. OUR OWN SHIPPED LINES DATED INSIDE THE WINDOW, and only when the days we hold
     *     actually span a fair part of it. Noon fails this: one PO delivered on 23 Jul
     *     against a 61-day sell-out window. Noon really did sell 23,274 units; we simply
     *     do not hold the POs that stocked most of them, and 363% would say something
     *     quite different and quite wrong.
     *
     *  3. NOTHING. The units are still reported on both sides, and the note says which
     *     upload would turn them into a ratio.
     *
     * @return array<string, mixed>
     */
    private function sellThroughFor(Channel $channel, int $sellOutUnits, ?array $out, ?array $held): array
    {
        $blank = [
            'pct' => null, 'basis' => null, 'note' => null, 'denominator' => null,
            'window_units' => null, 'window_days' => null, 'sitting' => null,
        ];

        if (! $channel->hasPurchaseOrders()) {
            return array_merge($blank, [
                'note' => 'Direct Fulfilment has no sell-in step — the order IS the sale, so a '
                    .'sell-through ratio here would be 100% by construction and mean nothing. '
                    .'The units and revenue beside it are real.',
            ]);
        }

        if ($out === null || $sellOutUnits <= 0) {
            return array_merge($blank, [
                'note' => 'No sell-out has been uploaded for this channel yet.',
            ]);
        }

        [$from, $to] = [$out['from'] ?? null, $out['to'] ?? null];
        $window = $this->sellInInWindow($channel, $from, $to);

        // 1. The channel's own count, aligned with the window by construction.
        $received = $held['net_received_units'] ?? null;

        if ($received !== null && $received > 0) {
            return [
                'pct' => round($sellOutUnits / $received * 100, 1),
                'basis' => "the channel's own Net Received Units for the same window",
                'note' => null,
                'denominator' => (int) $received,
                'window_units' => $window['units'],
                'window_days' => $window['days'],
                'sitting' => max(0, (int) $received - $sellOutUnits),
            ];
        }

        // 2. Our own shipped lines — but only if they span enough of the window to mean
        //    anything. Half is the line: below it the ratio is an artefact of what has
        //    been uploaded rather than a fact about the channel.
        $windowDays = $this->daysBetween($from, $to) ?? 0;
        $spansEnough = $windowDays > 0 && $window['days'] >= max(1, (int) floor($windowDays / 2));

        if ($window['units'] > 0 && $spansEnough) {
            return [
                'pct' => round($sellOutUnits / $window['units'] * 100, 1),
                'basis' => 'our own shipped lines dated inside the sell-out window',
                'note' => null,
                'denominator' => $window['units'],
                'window_units' => $window['units'],
                'window_days' => $window['days'],
                'sitting' => max(0, $window['units'] - $sellOutUnits),
            ];
        }

        return array_merge($blank, [
            'window_units' => $window['units'],
            'window_days' => $window['days'],
            'note' => sprintf(
                'Not comparable yet. The sell-out covers %d days (%s – %s), but we hold '
                .'shipments on only %d day%s inside it (%s units). A ratio between those two '
                .'would measure which files have been uploaded, not how the channel is '
                .'selling. Upload the POs and packing lists covering this window — or a '
                .'stock report carrying the channel\'s own received units — and the '
                .'percentage appears.',
                $windowDays,
                Carbon::parse($from)->format('j M'),
                Carbon::parse($to)->format('j M'),
                $window['days'],
                $window['days'] === 1 ? '' : 's',
                number_format($window['units'])
            ),
        ]);
    }

    /** One SKU's sell-through, against the channel's own received count or not at all. */
    private function skuSellThrough(Channel $channel, int $sellOutUnits, ?array $held): ?float
    {
        $received = $held['net_received_units'] ?? null;

        if (! $channel->hasPurchaseOrders() || $received === null || $received <= 0) {
            return null;
        }

        return round($sellOutUnits / $received * 100, 1);
    }

    /**
     * Units we shipped into a channel that are DATED INSIDE a window, and on how many
     * distinct days.
     *
     * The day count is what makes the alignment check possible: one delivery inside a
     * 61-day window is a data-coverage fact, not a sell-in figure.
     *
     * Dated on the delivery's own date, falling back to the PO's order date. That
     * fallback matters from M9 on: a Noon delivery date is TYPED BY A PERSON and is
     * legitimately blank until somebody enters it, so keying only on it would make Noon
     * sell-in vanish the moment we stopped inventing that date.
     *
     * @return array{units: int, days: int}
     */
    private function sellInInWindow(Channel $channel, $from, $to): array
    {
        if (blank($from) || blank($to)) {
            return ['units' => 0, 'days' => 0];
        }

        $dated = 'COALESCE(deliveries.delivered_on, purchase_orders.order_date)';

        $row = ShipmentLine::query()
            ->join('deliveries', 'shipment_lines.delivery_id', '=', 'deliveries.id')
            ->leftJoin('purchase_orders', function ($join) {
                $join->on('purchase_orders.po_number', '=', 'shipment_lines.po_number')
                    ->on('purchase_orders.marketplace', '=', 'shipment_lines.marketplace');
            })
            ->where('shipment_lines.channel', $channel->value)
            ->where('shipment_lines.stage', Stage::Final->value)
            ->whereRaw("date({$dated}) >= ?", [Carbon::parse($from)->toDateString()])
            ->whereRaw("date({$dated}) <= ?", [Carbon::parse($to)->toDateString()])
            ->when($this->filters->skus !== [], fn ($q) => $q->whereIn('shipment_lines.sku_id', $this->filters->skus))
            ->selectRaw("COALESCE(SUM(shipment_lines.qty), 0) as units, COUNT(DISTINCT date({$dated})) as days")
            ->first();

        return [
            'units' => (int) ($row->units ?? 0),
            'days' => (int) ($row->days ?? 0),
        ];
    }

    private function daysBetween($from, $to): ?int
    {
        if (blank($from) || blank($to)) {
            return null;
        }

        $from = $from instanceof Carbon ? $from : Carbon::parse($from);
        $to = $to instanceof Carbon ? $to : Carbon::parse($to);

        return max(1, (int) $from->diffInDays($to) + 1);
    }

    /**
     * The composite key everything joins on.
     *
     * A SKU id is only unique WITHIN a channel — the same ASIN sells on Amazon Retail and
     * on DFS, with its own stock and its own velocity on each — so the channel is part of
     * the key everywhere, never assumed away.
     */
    private function key(Channel|string $channel, string $skuId): string
    {
        return ($channel instanceof Channel ? $channel->value : $channel).'|'.$skuId;
    }
}
