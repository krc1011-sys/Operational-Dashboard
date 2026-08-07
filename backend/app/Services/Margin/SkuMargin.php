<?php

namespace App\Services\Margin;

use App\Enums\Channel;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProductChannelEconomics;
use App\Services\Reporting\FilterSet;
use App\Support\Currency;
use Illuminate\Support\Collection;

/**
 * SKU-level net margin, with the Amazon / Noon / Both selector (§Profitability, M7).
 *
 * The blueprint's question is blunt — "per SKU, is it profitable?" — and so is the answer
 * here: one row per product, its unit economics on each channel it sells through, and a
 * single blended figure when more than one channel is in view.
 *
 * ═══ THE RULE THIS CLASS EXISTS TO ENFORCE ═══
 *
 * "Both" is a REVENUE-WEIGHTED average of margin %, never a simple mean.
 *
 * The difference is not academic. A SKU making 30% on 100 units of Amazon revenue and 5%
 * on 1 unit of Noon revenue is making very nearly 30%; a simple mean calls it 17.5% and
 * would have us drop a product that is doing fine. So the blend is always
 *
 *     Σ (weight × profit)  ÷  Σ (weight × net receivable)
 *
 * which is the revenue-weighted mean by construction — the weights ARE the revenues — and
 * can never be pulled about by a channel with no money behind it.
 *
 * Unit COSTS blend differently, and the blueprint says so: those are unit-weighted, an
 * average over units rather than over money, because a cost per unit weighted by revenue
 * would flatter whichever channel charges the most.
 *
 * ═══ WHAT THE WEIGHT IS ═══
 *
 * Revenue means revenue we actually recorded: units shipped × what we bank per unit. When
 * a SKU has shipped units on the channels in view, those units are the weights.
 *
 * When it has none — a catalog product not yet ordered, or a product's DFS side, which has
 * no PO and no shipped units until M9 — there is no recorded revenue to weight by. Rather
 * than report nothing, the blend falls back to one unit of each channel,
 * which is still a revenue weighting (each channel weighted by its own per-unit
 * receivable) and is the honest reading of "if we sold these side by side".
 *
 * WHICH BASIS WAS USED TRAVELS WITH THE ANSWER, in `weight_basis`, and the screen states
 * it. A blended margin whose weighting you cannot see is a number you cannot check.
 */
class SkuMargin
{
    public const AMAZON = 'amazon';

    public const NOON = 'noon';

    public const BOTH = 'both';

    /** Weighted by units we really shipped. */
    public const BASIS_SHIPPED = 'shipped';

    /** Weighted per unit, because nothing has shipped on these channels yet. */
    public const BASIS_PER_UNIT = 'per_unit';

    /** The selector, as the blueprint names it. */
    public static function selectors(): array
    {
        return [
            self::AMAZON => 'Amazon',
            self::NOON => 'Noon',
            self::BOTH => 'Both',
        ];
    }

    /** Normalise whatever arrived in the query string. */
    public static function selector(?string $value): string
    {
        return array_key_exists((string) $value, self::selectors()) ? (string) $value : self::BOTH;
    }

    /**
     * Which channels a selector covers.
     *
     * "Amazon" is itself two channels — Vendor Central and DFS — so choosing it is
     * already a blend, and gets the same revenue weighting "Both" does.
     *
     * @return array<int, string>
     */
    public static function channelsFor(string $selector): array
    {
        return match (self::selector($selector)) {
            self::AMAZON => [Channel::AmazonRetail->value, Channel::AmazonDfs->value],
            self::NOON => [Channel::NoonRetail->value],
            default => [Channel::AmazonRetail->value, Channel::AmazonDfs->value, Channel::NoonRetail->value],
        };
    }

    /**
     * ═══ THE BLEND ═══
     *
     * Combine per-channel unit economics into one answer.
     *
     * Each row is one channel: `net_receivable`, `cogs` and `profit` PER UNIT (the
     * engine's own cached columns), plus `units` shipped on that channel.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function combine(iterable $rows): array
    {
        // Plain arrays rather than collections: this runs once per product per screen -
        // the real catalog is 914 of them - and the pipeline it would otherwise build is
        // more allocation than arithmetic.
        $rows = is_array($rows) ? $rows : iterator_to_array($rows);

        $shippedUnits = 0;
        $priced = 0;

        foreach ($rows as $row) {
            $shippedUnits += max(0, (int) ($row['units'] ?? 0));

            // A row with no selling price has no margin at all (the packaging materials,
            // §S). It is not a zero to average in - it is a question we cannot answer -
            // so it is left out of the blend and counted separately.
            if (($row['net_receivable'] ?? null) !== null && ($row['profit'] ?? null) !== null) {
                $priced++;
            }
        }

        $basis = $shippedUnits > 0 ? self::BASIS_SHIPPED : self::BASIS_PER_UNIT;

        $revenue = 0.0;
        $profit = 0.0;
        $costWeight = 0.0;
        $costTotal = 0.0;
        $revenueWeight = 0.0;

        foreach ($rows as $row) {
            // Under shipped weighting a channel with nothing shipped contributes nothing,
            // which is the correct answer and not an omission: we banked nothing there.
            $weight = $basis === self::BASIS_SHIPPED ? max(0, (int) ($row['units'] ?? 0)) : 1.0;

            if ($weight <= 0) {
                continue;
            }

            if (($row['net_receivable'] ?? null) !== null && ($row['profit'] ?? null) !== null) {
                $revenue += $weight * (float) $row['net_receivable'];
                $profit += $weight * (float) $row['profit'];
                $revenueWeight += $weight;
            }

            // Unit costs blend over UNITS, not over money (the blueprint's own caveat).
            if (($row['cogs'] ?? null) !== null) {
                $costTotal += $weight * (float) $row['cogs'];
                $costWeight += $weight;
            }
        }

        return [
            // Revenue-weighted, as a percentage. Null rather than 0 when there is no
            // revenue to take a share of - "we do not know" is not "we broke even".
            'margin_pct' => $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
            'net_receivable' => $revenueWeight > 0 ? round($revenue / $revenueWeight, 4) : null,
            'profit' => $revenueWeight > 0 ? round($profit / $revenueWeight, 4) : null,
            'cogs' => $costWeight > 0 ? round($costTotal / $costWeight, 4) : null,
            // The whole weighted revenue and profit, for rolling several SKUs together.
            'revenue_total' => round($revenue, 4),
            'profit_total' => round($profit, 4),
            'weight_basis' => $basis,
            'units' => $shippedUnits,
            'channels_priced' => $priced,
            'channels_total' => count($rows),
        ];
    }

    /**
     * How many SKUs the table draws before it stops.
     *
     * The whole catalog has to be BLENDED to sort by margin - you cannot know which are
     * the worst without costing all of them - but drawing two thousand rows with their
     * channel sub-rows makes a page nobody can use. So everything is computed and the
     * table is cut; the screen states the cut and the CSV export carries the lot. A cap
     * nobody is told about would read as "this is all of them".
     */
    public const TABLE_LIMIT = 200;

    public static function rows(string $selector, FilterSet $filters, ?int $limit = self::TABLE_LIMIT): Collection
    {
        $channels = self::channelsFor($selector);

        $products = self::productQuery($filters)
            ->with([
                'economics' => fn ($q) => $q->whereIn('channel', $channels),
                'anomalies' => fn ($q) => $q->needsReview(),
            ])
            ->get()
            ->filter(fn (Product $p) => $p->economics->isNotEmpty());

        $rows = self::build($products, $channels)
            ->values()
            /*
             * Worst margin first: the point of the screen is finding what loses money.
             *
             * Bundle components and unpriced rows sort to the END rather than being
             * dropped - somebody searching for one still needs to find it - but they are
             * out of the ranking, which is what §M8 asks for. A phantom loss at the top of
             * a watchlist pushes a real one off the bottom of it.
             */
            ->sortBy(fn ($row) => $row['bundle_component']
                ? PHP_INT_MAX
                : ($row['blend']['margin_pct'] ?? PHP_INT_MAX - 1))
            ->values();

        return $limit === null ? $rows : $rows->take($limit);
    }

    /**
     * The same answer for a set of products a screen has already chosen.
     *
     * This is what the Products tab's inline money columns read (§Profitability, M7).
     * It goes through the same build as the Profitability screen deliberately: two
     * screens showing the same product's margin must show the same number, and the only
     * way to guarantee that is for there to be one implementation of it.
     *
     * @param  array<int, int>  $productIds
     * @return Collection<int, array<string, mixed>> keyed by product id
     */
    public static function blendsForProducts(array $productIds, ?string $selector = null): Collection
    {
        $productIds = array_values(array_filter(array_unique($productIds)));

        if ($productIds === []) {
            return collect();
        }

        $channels = self::channelsFor($selector ?? self::BOTH);

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->with([
                'economics' => fn ($q) => $q->whereIn('channel', $channels),
                'anomalies' => fn ($q) => $q->needsReview(),
            ])
            ->get()
            ->filter(fn (Product $p) => $p->economics->isNotEmpty());

        return self::build($products, $channels);
    }

    /**
     * One row per product: each channel in view, plus the blend. Keyed by product id.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, array<string, mixed>>
     */
    private static function build(Collection $products, array $channels): Collection
    {
        $units = self::shippedUnits($products->pluck('id')->all(), $channels);

        return $products->mapWithKeys(function (Product $product) use ($units, $channels) {
            $perChannel = [];

            foreach ($channels as $channel) {
                $economics = $product->economics->firstWhere('channel', Channel::from($channel));

                if ($economics === null) {
                    continue;
                }

                // The engine falls back to the PRODUCT's cost when the channel row has
                // none of its own. Handing it the product we already hold keeps that
                // fallback from firing a query per row - a silent N+1 across the whole
                // catalog, and one that only shows up on the real 914-product file.
                $economics->setRelation('product', $product);

                $perChannel[] = self::channelRow($economics, (int) ($units[$product->id][$channel]['units'] ?? 0));
            }

            $blend = self::combine($perChannel);

            return [$product->id => [
                'product' => $product,
                'code' => $product->company_product_code,
                // M6 left seven catalog items waiting for a person's decision, and one of
                // them - BD62972744 - is a single code covering two products. A margin
                // computed on it is arithmetic over a fiction. The row still shows, with
                // the flag on it, because hiding it would be worse: the SKU is real and
                // somebody is looking for it.
                'flagged' => $product->relationLoaded('anomalies') && $product->anomalies->isNotEmpty(),
                // A bundle component is never sold on its own, so the selling price its
                // margin is computed against was never charged (M8). Its COST is real and
                // stays on screen; its margin is withheld rather than printed.
                'bundle_component' => (bool) $product->is_bundle_component,
                'name' => $product->name,
                'brand' => $product->brand,
                'category' => $product->category,
                'identifiers' => $product->relationLoaded('identifiers')
                    ? $product->identifiers->pluck('sku_id')->take(3)->all()
                    : [],
                'channels' => $perChannel,
                'blend' => $blend,
                'currency' => Currency::single(array_column($perChannel, 'currency')),
                // A SKU is "profitable" only when we can say so. Two ways we cannot: no
                // selling price at all, and a selling price that was never charged. Both
                // are "no verdict", which is a third state and not a failure.
                'profitable' => ($product->is_bundle_component || $blend['margin_pct'] === null)
                    ? null
                    : $blend['margin_pct'] > 0,
            ]];
        });
    }

    /** One channel's unit economics, straight off the engine's cached columns. */
    private static function channelRow(ProductChannelEconomics $e, int $units): array
    {
        return [
            'channel' => $e->channel,
            'label' => $e->channel?->label() ?? '—',
            'currency' => $e->currency,
            'rsp_ex_vat' => $e->rsp_ex_vat === null ? null : (float) $e->rsp_ex_vat,
            'invoice_value' => $e->invoice_value === null ? null : (float) $e->invoice_value,
            'net_receivable' => $e->net_receivable === null ? null : (float) $e->net_receivable,
            'cogs' => $e->cogs === null ? null : (float) $e->cogs,
            'profit' => $e->profit === null ? null : (float) $e->profit,
            'margin_pct' => $e->margin_pct === null ? null : round((float) $e->margin_pct * 100, 2),
            'cost_stack' => NetMarginEngine::costStack($e),
            'units' => $units,
        ];
    }

    /**
     * Units shipped per (product, channel), so the blend can weight by real revenue.
     *
     * @return array<int, array<string, array{units: int, sell_in: float}>>
     */
    private static function shippedUnits(array $productIds, array $channels): array
    {
        if ($productIds === []) {
            return [];
        }

        return PoLine::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('channel', $channels)
            ->selectRaw('product_id, channel,
                COALESCE(SUM(qty_shipped),0) as units,
                COALESCE(SUM(qty_shipped * unit_cost),0) as sell_in')
            ->groupBy('product_id', 'channel')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->keyBy(fn ($r) => $r->channel instanceof Channel ? $r->channel->value : (string) $r->channel)
                ->map(fn ($r) => ['units' => (int) $r->units, 'sell_in' => (float) $r->sell_in])
                ->all())
            ->all();
    }

    /**
     * The catalog, narrowed by the shared filter set.
     *
     * Only the product-shaped filters apply — brand, category, search, a pasted list of
     * identifiers. Dates, FCs and line statuses describe orders, and this screen is about
     * the SKU's economics rather than any one order of it.
     */
    private static function productQuery(FilterSet $filters)
    {
        return Product::query()
            ->with('identifiers')
            ->where('is_active', true)
            ->when($filters->brand !== null, fn ($q) => $q->where('brand', $filters->brand))
            ->when($filters->category !== null, fn ($q) => $q->where('category', $filters->category))
            ->when($filters->search !== null, function ($q) use ($filters) {
                $term = $filters->search;
                $q->where(function ($inner) use ($term) {
                    $inner->where('company_product_code', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('brand', 'like', "%{$term}%")
                        ->orWhere('barcode', 'like', "%{$term}%")
                        ->orWhereHas('identifiers', fn ($i) => $i->where('sku_id', 'like', "%{$term}%"));
                });
            })
            ->when($filters->skus !== [], fn ($q) => $q->whereHas(
                'identifiers', fn ($i) => $i->whereIn('sku_id', $filters->skus)
            ))
            ->orderBy('company_product_code');
    }
}
