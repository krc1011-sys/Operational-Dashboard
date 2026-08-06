<?php

namespace App\Http\Controllers;

use App\Models\PoLine;
use App\Models\Product;
use App\Models\SelloutRow;
use App\Services\Margin\SkuMargin;
use App\Services\Reporting\CsvExport;
use App\Services\Reporting\FilterSet;
use App\Services\Reporting\FulfilmentQuery;
use App\Support\Currency;
use App\Support\MoneyGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Products — the SKU analytics home (DESIGN_BRIEF §8).
 *
 * Everywhere else in OperON is organised by the paperwork: a PO, a delivery, an ASN.
 * This screen is organised by the PRODUCT, which is how sales and marketing think, and
 * it is where brand and category rollups live now that the catalog is loaded.
 *
 * WHAT IS HONEST HERE TODAY. Sell-through needs sell-out - what the channel's customers
 * actually bought - and that report is not ingested until M9. So the sell-in half is
 * real and the sell-out half is blank and says why. The quadrant plots what we can
 * actually measure: how much of a SKU we ship against how reliably we fill it. When
 * sell-out lands, the same quadrant gains the axis the brief asks for.
 *
 * Nothing here is a new business rule. Every figure is a sum of the engine's own cached
 * columns, grouped a different way.
 */
class ProductsController extends Controller
{
    public function index(Request $request): View|RedirectResponse|StreamedResponse
    {
        $filters = FilterSet::fromRequest($request);

        if ($request->isMethod('post')) {
            return redirect()->route('products.index', $filters->query());
        }

        $engine = new FulfilmentQuery($filters);

        if ($request->query('export') === 'csv') {
            return $this->export($filters, $engine);
        }

        $skus = $this->skuRows($filters);

        /*
         * The M7 inline unlock (§Profitability).
         *
         * Same rule as PO detail: the PIN is not on this route, because §O opens Products
         * to roles holding no money permission. Unlocking simply adds three columns and
         * the profitable/losing verdict to the SKU table. Sell-in - units x the
         * marketplace's price - is order value and stays open to everyone.
         */
        $canSeeMargin = MoneyGate::canSeeMargin();

        return view('reports.products', [
            'filters' => $filters,
            'canSeeMargin' => $canSeeMargin,
            'margins' => $canSeeMargin ? $this->marginsFor($skus) : collect(),
            'totals' => $engine->totals(),
            'brands' => $engine->grouped(FilterSet::GROUP_BRAND),
            'categories' => $engine->grouped(FilterSet::GROUP_CATEGORY),
            'skus' => $skus,
            // The labelled quadrant (§8). Points carry their product name so a dot is
            // never a mystery - the brief is explicit that abstract charts are out.
            'quadrant' => $this->quadrant($skus),
            'sellOutLoaded' => SelloutRow::count() > 0,
            'catalog' => [
                'products' => Product::count(),
                'brands' => Product::whereNotNull('brand')->distinct()->count('brand'),
                'categories' => Product::whereNotNull('category')->distinct()->count('category'),
                'sub_categories' => Product::whereNotNull('sub_category')->distinct()->count('sub_category'),
                'linked' => PoLine::whereNotNull('product_id')->count(),
                'lines' => PoLine::count(),
            ],
            'benchmarks' => config('operon.benchmarks'),
        ]);
    }

    /**
     * One row per SKU: what was ordered, what shipped, and what it is worth.
     *
     * Joined to the catalog for the product's name, brand and category - the point of
     * this screen is that a row says "Nice Touch Facial Tissue", not just an ASIN.
     */
    private function skuRows(FilterSet $filters)
    {
        return $filters->applyToLines(PoLine::query())
            ->leftJoin('products', 'po_lines.product_id', '=', 'products.id')
            ->selectRaw('
                po_lines.sku_id,
                MAX(po_lines.product_id) as product_id,
                MAX(po_lines.title) as title,
                MAX(products.brand) as brand,
                MAX(products.category) as category,
                MAX(products.sub_category) as sub_category,
                COUNT(DISTINCT po_lines.po_number) as po_count,
                COALESCE(SUM(po_lines.qty_net_accepted),0) as accepted,
                COALESCE(SUM(po_lines.qty_shipped),0) as shipped,
                COALESCE(SUM(po_lines.qty_not_booked),0) as not_booked,
                COALESCE(SUM(po_lines.qty_shipped * po_lines.unit_cost),0) as sell_in,
                COALESCE(SUM(CASE WHEN po_lines.qty_net_accepted > po_lines.qty_shipped
                    THEN (po_lines.qty_net_accepted - po_lines.qty_shipped) * po_lines.unit_cost
                    ELSE 0 END),0) as shortfall_value,
                MAX(po_lines.currency) as currency
            ')
            ->groupBy('po_lines.sku_id')
            ->orderByDesc('sell_in')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'sku_id' => $row->sku_id,
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                'title' => $row->title,
                'brand' => $row->brand,
                'category' => $row->category,
                'sub_category' => $row->sub_category,
                'po_count' => (int) $row->po_count,
                'accepted' => (int) $row->accepted,
                'shipped' => (int) $row->shipped,
                'not_booked' => (int) $row->not_booked,
                'sell_in' => (float) $row->sell_in,
                'shortfall_value' => (float) $row->shortfall_value,
                'currency' => Currency::code($row->currency),
                'fill_rate' => $row->accepted > 0
                    ? round((int) $row->shipped / (int) $row->accepted * 100, 1) : null,
            ]);
    }

    /**
     * Blended net margin for the SKUs on screen, keyed by sku_id (§Profitability, M7).
     *
     * Read through SkuMargin rather than reimplemented, so the margin on this tab and the
     * margin on the Profitability tab are the same number produced by the same code. It
     * is the revenue-weighted blend across every channel a product sells on — never a
     * simple average of the channel percentages.
     *
     * A SKU with no catalog product simply has no entry, and the column shows why.
     */
    private function marginsFor($skus)
    {
        $blends = SkuMargin::blendsForProducts($skus->pluck('product_id')->all());

        return $skus->filter(fn ($s) => $s['product_id'] !== null && $blends->has($s['product_id']))
            ->mapWithKeys(fn ($s) => [$s['sku_id'] => $blends[$s['product_id']]]);
    }

    /**
     * The quadrant: volume against how reliably we fill it.
     *
     * The interesting corner is high volume with a poor fill rate - a SKU the channel
     * keeps ordering and we keep failing to deliver, which costs the most and is the
     * hardest to see in a table. Points are labelled, per §1: a teammate should not have
     * to decode a chart.
     *
     * @return array<string, mixed>
     */
    private function quadrant($skus): array
    {
        $points = $skus->filter(fn ($s) => $s['accepted'] > 0 && $s['fill_rate'] !== null)
            ->sortByDesc('accepted')
            ->take(60)
            ->values();

        if ($points->isEmpty()) {
            return ['points' => collect(), 'max_units' => 0, 'target' => 0];
        }

        $maxUnits = max(1, $points->max('accepted'));
        $target = (float) config('operon.benchmarks.fill_rate_target');

        return [
            'points' => $points->map(fn ($s) => $s + [
                // Position as a percentage of the plot area; the view only draws.
                'x' => round($s['accepted'] / $maxUnits * 100, 2),
                'y' => round(min(100, $s['fill_rate']), 2),
                'risk' => $s['fill_rate'] < $target && $s['accepted'] >= $maxUnits * 0.25,
            ]),
            'max_units' => $maxUnits,
            'target' => $target,
        ];
    }

    private function export(FilterSet $filters, FulfilmentQuery $engine): StreamedResponse
    {
        $headers = ['SKU', 'Title', 'Brand', 'Category', 'Sub-category', 'POs',
            'Net accepted', 'Shipped', 'Not booked', 'Fill rate %', 'Currency',
            'Sell-in value', 'Shortfall value'];

        $rows = $this->skuRows($filters)->map(fn ($s) => [
            $s['sku_id'], $s['title'], $s['brand'], $s['category'], $s['sub_category'],
            $s['po_count'], $s['accepted'], $s['shipped'], $s['not_booked'], $s['fill_rate'],
            $s['currency'], round($s['sell_in'], 2), round($s['shortfall_value'], 2),
        ]);

        return CsvExport::stream('products', $headers, $rows, [0],
            ['OperON — Products', ...($filters->summary() ?: ['no filters applied'])]);
    }
}
