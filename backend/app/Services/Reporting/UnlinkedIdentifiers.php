<?php

namespace App\Services\Reporting;

use App\Enums\Marketplace;
use App\Models\Cancellation;
use App\Models\DfsOrder;
use App\Models\InventorySnapshot;
use App\Models\PoLine;
use App\Models\SelloutRow;
use App\Models\ShipmentLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Identifiers that turned up in a real file but are not in the master catalog (§S, M9).
 *
 * ═══ WHY THIS EXISTS ═══
 *
 * §K's rule is that nothing is ever dropped: a PO line, a delivered line, a sell-out row
 * or a stock row whose ASIN or NIN the catalog has never seen is STORED, flagged
 * `is_unmatched`, and links itself the moment the master sheet catches up. That is the
 * right behaviour, and it has one cost — those rows are then quietly invisible. They
 * carry no brand, no category, no cost and no margin, so they fall out of every rollup
 * on every screen without anything saying they are missing.
 *
 * On the real data the M8 example is exactly one Noon line: NIN Z2711427219A2E6791868Z-1,
 * "Brandsfinity Facial Tissue Cube Box, Pack of 3", 36 units ordered AND delivered on
 * PO 287285145169960, and absent from the master. One line is easy to add and impossible
 * to notice, which is the worst combination — and M9's sell-out and stock feeds turned
 * that one line into 194 identifiers, 30 of which we have actually traded in.
 *
 * ═══ WHY IT IS COMPUTED, NOT STORED ═══
 *
 * The obvious implementation is a MasterAnomaly row written when an import finds an
 * unmatched identifier. It would be wrong, for two reasons that both bite in normal use:
 *
 *  1. The master importer CLEARS open anomalies and rebuilds them on every upload, so a
 *     flag written by a Noon or sell-out import would be wiped by the next master upload
 *     — precisely the upload most likely to have FIXED it, or not.
 *  2. A stored flag goes stale the other way too. Add the SKU to the master and the row
 *     links itself silently; a stored anomaly would sit there claiming a problem that no
 *     longer exists until somebody dismissed it by hand.
 *
 * Deriving it from the fact tables makes it self-healing in both directions: the entry
 * appears the moment a file names an unknown SKU, and disappears the moment the catalog
 * learns it. Nothing to dismiss, nothing to reconcile.
 */
class UnlinkedIdentifiers
{
    /**
     * Where an unmatched identifier can turn up, and what to call that place.
     *
     * The name column differs between tables - the order side calls it `title` and the
     * DFS and cancellation feeds call it `description` - so it is named per source rather
     * than assumed, which is what a missing-column error at M9 taught us.
     *
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: string}>
     *                                                                                model, label, quantity column, name column
     */
    private static function sources(): array
    {
        return [
            'po_lines' => [PoLine::class, 'ordered on a PO', 'qty_accepted', 'title'],
            'shipment_lines' => [ShipmentLine::class, 'delivered', 'qty', 'title'],
            'sellout_rows' => [SelloutRow::class, 'sold to customers', 'shipped_units', 'title'],
            'inventory_snapshots' => [InventorySnapshot::class, 'holding stock', 'soh_units', 'title'],
            'dfs_orders' => [DfsOrder::class, 'a DFS order', 'qty', 'description'],
            'cancellations' => [Cancellation::class, 'cancelled', 'qty_cancelled', 'description'],
        ];
    }

    /**
     * One entry per (marketplace, identifier), with everywhere it was seen.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function all(int $limit = 200): Collection
    {
        $found = [];

        foreach (self::sources() as [$model, $label, $qtyColumn, $nameColumn]) {
            $rows = $model::query()
                ->whereNull('product_id')
                ->selectRaw(sprintf(
                    'marketplace, sku_id, MAX(%s) as title, COALESCE(SUM(%s), 0) as units, COUNT(*) as rows',
                    $nameColumn,
                    $qtyColumn
                ))
                ->groupBy('marketplace', 'sku_id')
                ->get();

            foreach ($rows as $row) {
                $marketplace = $row->marketplace instanceof Marketplace
                    ? $row->marketplace->value
                    : (string) $row->marketplace;

                $key = $marketplace.'|'.$row->sku_id;

                $found[$key] ??= [
                    'marketplace' => Marketplace::tryFrom($marketplace),
                    'sku_id' => $row->sku_id,
                    'kind' => self::kindOf($row->sku_id, $marketplace),
                    'title' => null,
                    'seen_in' => [],
                    'units' => 0,
                ];

                $found[$key]['title'] ??= $row->title;
                $found[$key]['title'] = $found[$key]['title'] ?: $row->title;
                $found[$key]['seen_in'][$label] = (int) $row->rows;
                $found[$key]['units'] += (int) $row->units;
            }
        }

        return collect($found)
            // The ones we have actually traded in first: a SKU we ordered and delivered
            // matters more than one that only appeared on a stock report.
            ->sortByDesc('units')
            ->take($limit)
            ->values();
    }

    /** How many identifiers are waiting to be added — the count a screen badges. */
    public static function count(): int
    {
        return self::all(10_000)->count();
    }

    /** Only the ones that cost us something to leave out: ordered, delivered or sold. */
    public static function traded(): Collection
    {
        return self::all()->filter(fn (array $entry) => array_intersect(
            array_keys($entry['seen_in']),
            ['ordered on a PO', 'delivered', 'sold to customers', 'a DFS order']
        ) !== []);
    }

    /**
     * What shape of identifier is this? Amazon ASINs are B0-prefixed and Noon NINs are
     * Z…Z-n; anything else is worth naming as neither, because it usually means a column
     * was read from the wrong place.
     *
     * The length is a RANGE rather than the exact 10 a real ASIN has, because this label
     * only helps somebody read the fix list - being strict here would print "ASIN?" beside
     * a perfectly good ASIN and send them looking for a problem that is not there.
     */
    private static function kindOf(string $skuId, string $marketplace): string
    {
        return match (true) {
            (bool) preg_match('/^B0[A-Z0-9]{8,10}$/i', $skuId) => 'ASIN',
            (bool) preg_match('/^Z[A-Z0-9]+Z(-\d+)?$/i', $skuId) => 'NIN',
            (bool) preg_match('/^\d{8,14}$/', $skuId) => 'barcode',
            default => $marketplace === Marketplace::Noon->value ? 'NIN?' : 'ASIN?',
        };
    }

    /** A one-line description for a console or a log. */
    public static function describe(array $entry): string
    {
        $where = implode(', ', array_keys($entry['seen_in']));

        return sprintf(
            '%s %s — %s%s (%s)',
            $entry['kind'],
            $entry['sku_id'],
            $entry['title'] ? '"'.Str::limit($entry['title'], 44).'", ' : '',
            number_format($entry['units']).' units',
            $where
        );
    }
}
