<?php

namespace App\Services\Reporting;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Models\Cancellation;
use App\Models\Delivery;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ShipmentLine;
use App\Models\SourceFile;
use App\Services\Analytics\SellThroughEngine;
use App\Support\Currency;
use Illuminate\Support\Collection;

/**
 * The panels behind the Overview screen (DESIGN_BRIEF §8).
 *
 * READ-ONLY, AND DELIBERATELY SO. Nothing here defines a business rule or writes a row:
 * every figure is a sum over columns the engine already cached, narrowed by the filters
 * the user chose. A number on this screen and a number in the engine cannot disagree,
 * because this class does not know how to calculate anything the engine does not.
 *
 * Where the data for a panel has not been ingested yet, the panel returns null and the
 * screen says so in a sentence. It never fills the space with a plausible-looking figure,
 * because a made-up number on a dashboard is worse than an empty one: nobody checks a
 * number that looks fine. M9 extends that rule from "no data" to "data that does not
 * line up" - see the sell-through panel, which withholds a percentage whenever its two
 * halves cover different days.
 */
class OverviewPanels
{
    public function __construct(private readonly FilterSet $filters) {}

    /**
     * Performance by fulfilment centre (§5 "FC section").
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fulfilmentCentres(int $limit = 8): Collection
    {
        return $this->filters->applyToLines(PoLine::query())
            ->selectRaw('
                ship_to_fc,
                COUNT(DISTINCT po_number) as po_count,
                COALESCE(SUM(qty_net_accepted), 0) as accepted,
                COALESCE(SUM(qty_shipped), 0) as shipped,
                COALESCE(SUM(qty_net_accepted * unit_cost), 0) as value,
                COALESCE(SUM(qty_shipped * unit_cost), 0) as shipped_value,
                MAX(currency) as currency
            ')
            ->whereNotNull('ship_to_fc')
            ->groupBy('ship_to_fc')
            ->orderByDesc('value')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'fc' => $row->ship_to_fc,
                'po_count' => (int) $row->po_count,
                // The bar is ORDER value - what the FC has asked us for - which answers
                // "where is the volume" (v3: "PO value & fill rate by FC"). Shipped sits
                // beside it, because the gap between the two is the story.
                'accepted' => (int) $row->accepted,
                'shipped' => (int) $row->shipped,
                'value' => (float) $row->value,
                'shipped_value' => (float) $row->shipped_value,
                'currency' => Currency::code($row->currency),
                'fill_rate' => $row->accepted > 0
                    ? round((int) $row->shipped / (int) $row->accepted * 100, 1)
                    : null,
                /*
                 * ⚠ Rush FCs are a real Amazon concept (Amazon Now) and the design calls
                 * for tagging them. We have no column saying which FC is a rush centre -
                 * it is not in any file we ingest - so nothing is tagged rather than
                 * guessed from the code. Add the list to config when it is known.
                 */
                'rush' => in_array($row->ship_to_fc, config('operon.rush_fcs', []), true),
            ]);
    }

    /**
     * Revenue and health per sales channel (§5 "Channel mix").
     *
     * Every Phase-1 channel is listed even when it has no rows yet, because "Noon: not
     * loaded" is information and a missing row is not.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function channelMix(): Collection
    {
        $rows = $this->filters->applyToLines(PoLine::query())
            ->selectRaw('
                channel,
                COUNT(DISTINCT po_number) as po_count,
                COALESCE(SUM(qty_requested), 0) as requested,
                COALESCE(SUM(qty_accepted), 0) as accepted,
                COALESCE(SUM(qty_net_accepted), 0) as net_accepted,
                COALESCE(SUM(qty_shipped), 0) as shipped,
                COALESCE(SUM(qty_shipped * unit_cost), 0) as value,
                MAX(currency) as currency
            ')
            ->groupBy('channel')
            ->get()
            ->keyBy(fn ($row) => $row->channel instanceof Channel ? $row->channel->value : (string) $row->channel);

        return collect(Channel::cases())->map(function (Channel $channel) use ($rows) {
            $row = $rows->get($channel->value);

            return [
                'channel' => $channel,
                'badge' => match ($channel) {
                    Channel::AmazonRetail => ['AMZ', 'linear-gradient(135deg,#ff9900,#e47911)', '#fff'],
                    Channel::AmazonDfs => ['DFS', 'linear-gradient(135deg,#146eb4,#0f5a95)', '#fff'],
                    Channel::NoonRetail => ['NOON', 'linear-gradient(135deg,#feee00,#e6d500)', '#3a3400'],
                },
                'loaded' => $row !== null,
                'po_count' => (int) ($row->po_count ?? 0),
                'units' => (int) ($row->shipped ?? 0),
                'value' => (float) ($row->value ?? 0),
                'currency' => Currency::code($row->currency ?? null),
                'fill_rate' => ($row && $row->net_accepted > 0)
                    ? round((int) $row->shipped / (int) $row->net_accepted * 100, 1) : null,
                /*
                 * Amazon only. Noon has no accept step - it orders what it orders - so
                 * accepted always equals requested and a "100%" here would advertise a
                 * negotiation that never happened (§Q). Null reads as "not applicable",
                 * which is the truth, and matches PurchaseOrder::confirmationRate().
                 */
                'confirmation_rate' => ($row && $row->requested > 0
                    && $channel->marketplace() === Marketplace::Amazon)
                    ? round((int) $row->accepted / (int) $row->requested * 100, 1) : null,
            ];
        });
    }

    /**
     * Sell-in against sell-out (§5 "Sell-through block"), live from M9.
     *
     * READ THROUGH THE ENGINE, NOT RECOMPUTED. The rule about which denominator a
     * sell-through ratio may use — and when there is no honest one — lives in
     * SellThroughEngine, and reproducing any part of it here is how the Overview tile
     * and the Products page would start disagreeing.
     *
     * Still returns null when nothing is loaded, so the panel's honest empty state
     * survives untouched.
     *
     * @return array<string, mixed>|null
     */
    public function sellThrough(): ?array
    {
        $engine = new SellThroughEngine($this->filters);

        if (! $engine->hasSellOut()) {
            return null;
        }

        $channels = $engine->byChannel()->filter(fn (array $c) => $c['sell_out_units'] > 0)->values();

        /*
         * The headline is the channels that HAVE an honest ratio, blended on units.
         * Channels whose windows do not line up are listed underneath with their reason
         * instead of being folded into an average that would hide the gap.
         */
        $comparable = $channels->filter(fn (array $c) => $c['sell_through_pct'] !== null);

        $sellOutUnits = (int) $comparable->sum('sell_out_units');
        $denominator = (int) $comparable->sum('sell_through_denominator');

        return [
            'pct' => $denominator > 0 ? round($sellOutUnits / $denominator * 100, 1) : null,
            'sell_in_units' => $denominator,
            'sell_out_units' => (int) $channels->sum('sell_out_units'),
            'sell_out_revenue' => (float) $channels->sum('sell_out_revenue'),
            'sitting' => max(0, $denominator - $sellOutUnits),
            'channels' => $channels,
            'comparable' => $comparable->count(),
            'not_comparable' => $channels->filter(fn (array $c) => $c['sell_through_pct'] === null)->values(),
            'currency' => $channels->first()['currency'] ?? Currency::code(null),
        ];
    }

    /**
     * Stock on hand and how long it lasts, per channel (§P/§R, M9).
     *
     * Null until stock is ingested. DFS carries its provisional label all the way here,
     * because the tile is exactly where somebody would read the number and act on it.
     *
     * @return array<string, mixed>|null
     */
    public function cover(): ?array
    {
        $engine = new SellThroughEngine($this->filters);

        if (! $engine->hasStock()) {
            return null;
        }

        $channels = $engine->byChannel()->filter(fn (array $c) => $c['soh_units'] !== null)->values();
        $rows = $engine->skuRows();
        $lists = $engine->watchlists($rows);

        return [
            'channels' => $channels,
            'soh_units' => (int) $channels->sum('soh_units'),
            'aged_90_units' => (int) $channels->sum(fn (array $c) => $c['aged_90_units'] ?? 0),
            'provisional' => $channels->contains(fn (array $c) => $c['stock_is_provisional']),
            'overstocking' => $lists['overstocking']['all']->count(),
            'overstocking_units' => $lists['overstocking']['units'],
            'under_supplying' => $lists['under_supplying']['all']->count(),
            'thresholds' => $lists['thresholds'],
        ];
    }

    /**
     * "Act today" (§5) — the exceptions, ranked by what they cost us.
     *
     * Ranked by money first because that is the honest priority order, and each row
     * carries the screen that answers it. Only real, currently-true conditions appear;
     * an empty list means there is genuinely nothing waiting, and says so.
     *
     * @return array<int, array<string, mixed>>
     */
    public function alerts(): array
    {
        $alerts = [];
        $engine = new FulfilmentQuery($this->filters);
        $totals = $engine->totals();

        // 1. Units accepted and not shipped - the biggest recurring number on the screen.
        if ($totals['shortfall_units'] > 0) {
            $alerts[] = [
                'severity' => 'crit',
                'weight' => $totals['shortfall_value'],
                'title' => Currency::plain($totals['shortfall_value'], $totals['currency']).' of accepted units not shipped',
                'detail' => number_format($totals['shortfall_units']).' units short across '
                    .number_format($totals['sku_count']).' SKUs',
                'action' => 'View shortfall →',
                'href' => route('fulfilment.index', ['view' => 'outstanding']),
            ];
        }

        // 2. Cancellations nobody has answered. These hold real figures in limbo (§G).
        $awaiting = Cancellation::needsDecision()->count();

        if ($awaiting > 0) {
            $units = (int) Cancellation::needsDecision()->sum('qty_cancelled');

            $alerts[] = [
                'severity' => 'crit',
                'weight' => $units * 50,
                'title' => $awaiting.' cancellation'.($awaiting === 1 ? '' : 's').' waiting for a decision',
                'detail' => number_format($units).' units are neither cancelled nor confirmed until somebody answers',
                'action' => 'Decide →',
                'href' => route('cancellations.index'),
            ];
        }

        // 3. POs past the turnaround benchmark.
        $late = $this->filters->applyToPurchaseOrders(PurchaseOrder::query())
            ->where('is_complete', false)->breachingBenchmark()->count();

        if ($late > 0) {
            $alerts[] = [
                'severity' => 'w',
                'weight' => $late * 1000,
                'title' => $late.' PO'.($late === 1 ? '' : 's').' past the '
                    .config('operon.benchmarks.turnaround_days').'-day turnaround goal',
                'detail' => 'Open longer than the benchmark, whether or not they have started shipping',
                'action' => 'View →',
                'href' => route('fulfilment.index'),
            ];
        }

        // 4. Units shipped against a cancellation - real chargeback exposure.
        $chargeback = (int) Cancellation::chargebackExposure()->sum('qty_delivered_anyway');

        if ($chargeback > 0) {
            $alerts[] = [
                'severity' => 'w',
                'weight' => $chargeback * 40,
                'title' => number_format($chargeback).' units shipped after a cancellation',
                'detail' => 'Amazon may charge these back — delivered anyway by decision',
                'action' => 'Review →',
                'href' => route('cancellations.index'),
            ];
        }

        /*
         * 5. Shipped Noon deliveries with no confirmed delivery date (M9).
         *
         * These used to be invisible, because M8 quietly used the upload day and the
         * turnaround looked answered. It is a real gap now and it is listed as one: the
         * PO has no turnaround at all until somebody types the date.
         */
        $undated = Delivery::awaitingDate()->count();

        if ($undated > 0) {
            $alerts[] = [
                'severity' => 'w',
                'weight' => $undated * 800,
                'title' => $undated.' shipped deliver'.($undated === 1 ? 'y' : 'ies').' need a delivery date',
                'detail' => 'Noon supplies only an estimate, so turnaround waits until the real date is entered',
                'action' => 'Enter it →',
                'href' => route('deliveries.index', ['view' => 'shipped']),
            ];
        }

        // 6. Deliveries booked but never finalised.
        $awaitingFinal = Delivery::awaitingFinal()->count();

        if ($awaitingFinal > 0) {
            $alerts[] = [
                'severity' => 'g',
                'weight' => $awaitingFinal * 100,
                'title' => $awaitingFinal.' deliver'.($awaitingFinal === 1 ? 'y' : 'ies').' booked but not shipped',
                'detail' => 'Interim packing list uploaded; the final has not arrived',
                'action' => 'Open →',
                'href' => route('deliveries.index'),
            ];
        }

        // 7. Packing lines whose PO has not been uploaded yet. Normal during rollout (§K).
        $unmatched = ShipmentLine::unmatched()->count();

        if ($unmatched > 0) {
            $alerts[] = [
                'severity' => 'g',
                'weight' => 1,
                'title' => number_format($unmatched).' packing lines waiting for their PO',
                'detail' => 'Stored, not dropped — they link themselves when those PO exports upload',
                'action' => 'Upload →',
                'href' => route('uploads.index'),
            ];
        }

        // 8. Feeds that have gone stale (§J).
        foreach (SourceFile::overdueTypes() as $overdue) {
            $alerts[] = [
                'severity' => 'w',
                'weight' => 500,
                'title' => $overdue['type']->label().' is overdue',
                'detail' => $overdue['days'] === null
                    ? 'Never uploaded — expected every '.$overdue['cadence'].' days'
                    : 'Last uploaded '.$overdue['days'].' days ago — expected every '.$overdue['cadence'],
                'action' => 'Upload →',
                'href' => route('uploads.index'),
            ];
        }

        usort($alerts, fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return $alerts;
    }

    /**
     * Completed against in-flight (§8) — why a blended fill rate reads low.
     *
     * A PO still collecting deliveries drags the average down without anything being
     * wrong, so the two are shown apart rather than averaged together.
     *
     * @return array<string, mixed>
     */
    public function inFlight(): array
    {
        $orders = $this->filters->applyToPurchaseOrders(PurchaseOrder::query());

        $completedIds = (clone $orders)->where('is_complete', true)->pluck('id');

        $completedFill = $completedIds->isEmpty() ? null : $this->fillRateFor($completedIds->all());

        return [
            'completed' => $completedIds->count(),
            'completed_fill' => $completedFill,
            'open' => (clone $orders)->where('is_complete', false)->count(),
        ];
    }

    private function fillRateFor(array $purchaseOrderIds): ?float
    {
        $row = PoLine::whereIn('purchase_order_id', $purchaseOrderIds)
            ->selectRaw('COALESCE(SUM(qty_shipped),0) as shipped, COALESCE(SUM(qty_net_accepted),0) as accepted')
            ->first();

        return $row && $row->accepted > 0 ? round($row->shipped / $row->accepted * 100, 1) : null;
    }

    /** How much of the catalog the screens can actually name (brand/category rollups). */
    public function catalogCoverage(): array
    {
        $total = PoLine::count();

        return [
            'products' => Product::count(),
            'lines_linked' => $total > 0 ? PoLine::whereNotNull('product_id')->count() : 0,
            'lines_total' => $total,
        ];
    }
}
