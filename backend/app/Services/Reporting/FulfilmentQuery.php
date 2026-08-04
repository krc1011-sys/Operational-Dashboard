<?php

namespace App\Services\Reporting;

use App\Models\PoLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The arithmetic behind the Overview and Fulfilment screens, in one place.
 *
 * Every figure here is a sum over PO lines the filters have already narrowed, and every
 * definition matches the one the engine cached on the line itself - so the screens agree
 * with the engine by construction rather than by coincidence.
 *
 * SQL note: shortfall is written as CASE WHEN rather than GREATEST/MAX, because those
 * two spell the same idea differently in MySQL and SQLite and the tests run on SQLite.
 */
class FulfilmentQuery
{
    private const SHORTFALL_UNITS = 'CASE WHEN qty_net_accepted > qty_shipped THEN qty_net_accepted - qty_shipped ELSE 0 END';

    private const SHORTFALL_VALUE = 'CASE WHEN qty_net_accepted > qty_shipped THEN (qty_net_accepted - qty_shipped) * unit_cost ELSE 0 END';

    public function __construct(private readonly FilterSet $filters) {}

    /** @return Builder<PoLine> */
    public function lines(): Builder
    {
        return $this->filters->applyToLines(PoLine::query());
    }

    /**
     * The headline totals (§E, §L). Returned as one row so a screen makes one query.
     *
     * @return array<string, float|int|null>
     */
    public function totals(): array
    {
        $row = $this->lines()
            ->selectRaw('
                COUNT(*) as line_count,
                COUNT(DISTINCT po_number) as po_count,
                COUNT(DISTINCT sku_id) as sku_count,
                COALESCE(SUM(qty_requested), 0) as requested,
                COALESCE(SUM(qty_accepted), 0) as accepted,
                COALESCE(SUM(qty_net_accepted), 0) as net_accepted,
                COALESCE(SUM(qty_booked), 0) as booked,
                COALESCE(SUM(qty_shipped), 0) as shipped,
                COALESCE(SUM(qty_not_booked), 0) as not_booked,
                COALESCE(SUM(qty_cancelled_honoured), 0) as cancelled,
                COALESCE(SUM('.self::SHORTFALL_UNITS.'), 0) as shortfall_units,
                COALESCE(SUM('.self::SHORTFALL_VALUE.'), 0) as shortfall_value,
                COALESCE(SUM(qty_shipped * unit_cost), 0) as shipped_value,
                COALESCE(SUM(qty_booked * unit_cost), 0) as booked_value
            ')
            ->first();

        $netAccepted = (int) $row->net_accepted;
        $requested = (int) $row->requested;

        return [
            'line_count' => (int) $row->line_count,
            'po_count' => (int) $row->po_count,
            'sku_count' => (int) $row->sku_count,
            'requested' => $requested,
            'accepted' => (int) $row->accepted,
            'net_accepted' => $netAccepted,
            'booked' => (int) $row->booked,
            'shipped' => (int) $row->shipped,
            'not_booked' => (int) $row->not_booked,
            'cancelled' => (int) $row->cancelled,
            'shortfall_units' => (int) $row->shortfall_units,
            'shortfall_value' => (float) $row->shortfall_value,
            'shipped_value' => (float) $row->shipped_value,
            'booked_value' => (float) $row->booked_value,
            // Fill rate = shipped ÷ net accepted (§E).
            'fill_rate' => $netAccepted > 0 ? round((int) $row->shipped / $netAccepted * 100, 2) : null,
            // Confirmation rate = accepted ÷ requested (§L). Amazon only - Noon has no
            // accept step (§Q) - but the filter decides that, not this class.
            'confirmation_rate' => $requested > 0 ? round((int) $row->accepted / $requested * 100, 2) : null,
        ];
    }

    /**
     * Grouped rows for the §M "Group by SKU / Brand / Category" selector.
     *
     * Brand and category come from the master catalog, so lines whose SKU the catalog
     * does not know yet fall into one honest "not in the catalog yet" bucket rather
     * than being dropped from the report.
     */
    public function grouped(string $groupBy): Collection
    {
        $query = $this->lines();

        [$select, $key] = match ($groupBy) {
            FilterSet::GROUP_SKU => ['po_lines.sku_id', 'sku_id'],
            FilterSet::GROUP_BRAND => ['products.brand', 'brand'],
            FilterSet::GROUP_CATEGORY => ['products.category', 'category'],
            default => ['po_lines.sku_id', 'sku_id'],
        };

        if (in_array($groupBy, [FilterSet::GROUP_BRAND, FilterSet::GROUP_CATEGORY], true)) {
            $query->leftJoin('products', 'po_lines.product_id', '=', 'products.id');
        }

        return $query
            ->selectRaw("
                {$select} as group_key,
                COUNT(*) as line_count,
                COUNT(DISTINCT po_lines.sku_id) as sku_count,
                COALESCE(SUM(qty_accepted), 0) as accepted,
                COALESCE(SUM(qty_net_accepted), 0) as net_accepted,
                COALESCE(SUM(qty_booked), 0) as booked,
                COALESCE(SUM(qty_shipped), 0) as shipped,
                COALESCE(SUM(".self::SHORTFALL_UNITS.'), 0) as shortfall_units,
                COALESCE(SUM('.self::SHORTFALL_VALUE.'), 0) as shortfall_value,
                MAX(po_lines.title) as title
            ')
            ->groupBy($select)
            ->orderByRaw('SUM('.self::SHORTFALL_UNITS.') DESC')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->group_key ?? ($key === 'sku_id' ? '—' : 'Not in the catalog yet'),
                'title' => $row->title,
                'line_count' => (int) $row->line_count,
                'sku_count' => (int) $row->sku_count,
                'accepted' => (int) $row->accepted,
                'net_accepted' => (int) $row->net_accepted,
                'booked' => (int) $row->booked,
                'shipped' => (int) $row->shipped,
                'shortfall_units' => (int) $row->shortfall_units,
                'shortfall_value' => (float) $row->shortfall_value,
                'fill_rate' => $row->net_accepted > 0
                    ? round($row->shipped / $row->net_accepted * 100, 2)
                    : null,
            ]);
    }

    /** Colour a percentage against its benchmark, Amazon-scorecard style (§M). */
    public static function rate(?float $value, float $target): string
    {
        if ($value === null) {
            return 'neutral';
        }

        return match (true) {
            $value >= $target => 'good',
            // Amazon's defect target is 5%, so within 5 points of target is amber.
            $value >= $target - 5 => 'warn',
            default => 'bad',
        };
    }
}
