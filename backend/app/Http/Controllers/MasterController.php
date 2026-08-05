<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureMoneyPinVerified;
use App\Models\MasterAnomaly;
use App\Models\Product;
use App\Models\ProductChannelEconomics;
use App\Services\Margin\NetMarginEngine;
use App\Services\Reporting\CsvExport;
use App\Support\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The master catalog screen (§S) — Path A, an Excel-like grid inside OperON.
 *
 * Two audiences, one screen, and the difference between them is enforced on the server:
 *
 *   - ANYONE WITH `view-master` sees the catalog itself: codes, names, brands,
 *     categories, sub-categories, owners, origins, suppliers, identifiers. This is the
 *     product lookup the warehouse and sales use, and it holds no money.
 *   - ADMIN, PIN-VERIFIED sees the unit economics and can edit. That is §S's
 *     "Admin-only + PIN/password", and it covers cost, fees, marketing and margin.
 *
 * The PIN is not on the route, because that would bounce everyone else off a screen §O
 * says they may see. It gates the money columns and every write instead, which is the
 * same protection applied where the sensitive data actually is.
 */
class MasterController extends Controller
{
    public function index(Request $request): View|StreamedResponse
    {
        $canEdit = $this->canEdit($request);

        $products = Product::query()
            ->with(['economics', 'identifiers'])
            ->when($request->string('q')->toString(), function ($query, string $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('company_product_code', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('brand', 'like', "%{$term}%")
                        ->orWhere('barcode', 'like', "%{$term}%")
                        ->orWhereHas('identifiers', fn ($i) => $i->where('sku_id', 'like', "%{$term}%"));
                });
            })
            ->when($request->string('brand')->toString(), fn ($q, $b) => $q->where('brand', $b))
            ->when($request->string('category')->toString(), fn ($q, $c) => $q->where('category', $c))
            ->when($request->string('sub_category')->toString(), fn ($q, $s) => $q->where('sub_category', $s))
            ->when($request->string('owner')->toString(), fn ($q, $o) => $q->where('owner', $o))
            ->when($request->boolean('flagged'), fn ($q) => $q->whereHas('anomalies', fn ($a) => $a->open()))
            ->orderBy('company_product_code');

        if ($request->query('export') === 'csv') {
            return $this->export($request, (clone $products), $canEdit);
        }

        return view('master.index', [
            'products' => $products->paginate(50)->withQueryString(),
            'canEdit' => $canEdit,
            'canSeeMoney' => $this->canSeeMoney($request),
            'pinUnlockUrl' => route('money-pin.prompt'),
            'anomalies' => MasterAnomaly::open()
                ->orderByRaw("CASE WHEN severity = 'review' THEN 0 ELSE 1 END")
                ->orderBy('company_product_code')
                ->limit(200)
                ->get(),
            'reviewCount' => MasterAnomaly::needsReview()->count(),
            'noteCount' => MasterAnomaly::open()->where('severity', MasterAnomaly::SEVERITY_NOTE)->count(),
            'filters' => $request->only(['q', 'brand', 'category', 'sub_category', 'owner', 'flagged']),
            'brands' => Product::whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand'),
            'categories' => Product::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'subCategories' => Product::whereNotNull('sub_category')->distinct()->orderBy('sub_category')->pluck('sub_category'),
            'owners' => Product::whereNotNull('owner')->distinct()->orderBy('owner')->pluck('owner'),
            'costBasis' => config('operon.cost_basis', 'latest'),
            'stats' => [
                'products' => Product::count(),
                'channel_rows' => ProductChannelEconomics::count(),
            ],
        ]);
    }

    /**
     * Save one edited cell.
     *
     * Deliberately one field at a time: the grid saves as you leave a cell, so a failure
     * is about the cell you just left rather than a whole row you have moved on from.
     */
    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'field' => 'required|string|in:'.implode(',', Product::EDITABLE),
            'value' => 'nullable',
        ]);

        $product->{$data['field']} = $this->clean($data['value']);
        $product->updated_by = $request->user()->id;
        $product->save();

        // Product cost feeds every channel's COGS, so its channels are recomputed.
        if ($data['field'] === 'product_cost') {
            foreach ($product->economics as $economics) {
                $economics->setRelation('product', $product);
                NetMarginEngine::apply($economics);
                $economics->save();
            }
        }

        return response()->json([
            'saved' => true,
            'value' => $product->{$data['field']},
            'economics' => $product->economics()->get()->mapWithKeys(
                fn ($e) => [$e->id => $this->derivedFor($e)]
            ),
        ]);
    }

    public function updateEconomics(Request $request, ProductChannelEconomics $economics): JsonResponse
    {
        $data = $request->validate([
            'field' => 'required|string|in:'.implode(',', ProductChannelEconomics::EDITABLE),
            'value' => 'nullable',
        ]);

        $economics->{$data['field']} = $this->clean($data['value']);
        $economics->updated_by = $request->user()->id;
        // Marks the row as hand-edited, so a later re-import of the sheet can tell what a
        // person changed from what the file said.
        $economics->is_manual = true;

        // Recompute rather than trust: a typed input changes the answer, and the answer
        // is always the engine's (§S).
        NetMarginEngine::apply($economics);
        $economics->save();

        return response()->json([
            'saved' => true,
            'value' => $economics->{$data['field']},
            'derived' => $this->derivedFor($economics->fresh()),
        ]);
    }

    /** Add a product by hand — §S's "inline add" (the grid's new-row form). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_product_code' => 'required|string|max:40|unique:products,company_product_code',
            'name' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
        ]);

        $product = Product::create($data + [
            'is_active' => true,
            'cost_basis' => config('operon.cost_basis', 'latest'),
            'updated_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('master.index', ['q' => $product->company_product_code])
            ->with('status', "Added {$product->company_product_code}. Its prices and fees are empty until you fill them in or the next master upload brings them.");
    }

    /**
     * Retire a product rather than delete it.
     *
     * A hard delete would orphan every PO line, shipment line and cancellation already
     * pointing at it, and those are historical fact. Retiring keeps the history readable
     * and takes the product out of the working catalog.
     */
    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $product->update(['is_active' => false, 'updated_by' => $request->user()->id]);

        return back()->with('status',
            "{$product->company_product_code} is retired. Past orders still show it; it will not appear as a current product.");
    }

    /** Mark a flagged item as dealt with. The note is kept — it is the audit trail. */
    public function resolveAnomaly(Request $request, MasterAnomaly $anomaly): RedirectResponse
    {
        $anomaly->update([
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
            'resolution_note' => $request->string('note')->toString() ?: null,
        ]);

        return back()->with('status', 'Marked as reviewed. It will not come back unless the next upload finds it again.');
    }

    // --- helpers ----------------------------------------------------------

    private function derivedFor(ProductChannelEconomics $e): array
    {
        return [
            'net_receivable' => $e->net_receivable === null ? null : Currency::amount($e->net_receivable, $e->currency),
            'cogs' => $e->cogs === null ? null : Currency::amount($e->cogs, $e->currency),
            'profit' => $e->profit === null ? null : Currency::amount($e->profit, $e->currency),
            'profit_pct' => $e->profit_pct === null ? null : round((float) $e->profit_pct * 100, 2).'%',
            'margin_pct' => $e->margin_pct === null ? null : round((float) $e->margin_pct * 100, 2).'%',
        ];
    }

    /** Blank means "no value", not zero — the two mean different things here. */
    private function clean(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return ($value === '' || $value === null) ? null : $value;
    }

    private function canEdit(Request $request): bool
    {
        return $request->user()->can('manage-master')
            && EnsureMoneyPinVerified::verified($request);
    }

    private function canSeeMoney(Request $request): bool
    {
        return $request->user()->canAny(['view-margin', 'view-sku-cost'])
            && EnsureMoneyPinVerified::verified($request);
    }

    private function export(Request $request, $query, bool $withMoney): StreamedResponse
    {
        $money = $this->canSeeMoney($request);

        $headers = ['Company Product Code', 'Name', 'Brand', 'Category', 'Sub-category',
            'Owner', 'Origin', 'Barcode', 'Suppliers', 'Cartons', 'Active', 'Identifiers'];

        if ($money) {
            $headers = array_merge($headers, ['Channel', 'Currency', 'RSP ex VAT',
                'Platform fees %', 'Product cost', 'Marketing', 'OPEX', 'Packaging',
                'Net receivable', 'COGS', 'Profit', 'Profit %', 'Margin %']);
        }

        $rows = function () use ($query, $money) {
            foreach ($query->cursor() as $product) {
                $base = [
                    $product->company_product_code, $product->name, $product->brand,
                    $product->category, $product->sub_category, $product->owner,
                    $product->origin, $product->barcode, $product->suppliers,
                    $product->cartons, $product->is_active ? 'yes' : 'retired',
                    $product->identifiers->pluck('sku_id')->implode(' '),
                ];

                if (! $money) {
                    yield $base;

                    continue;
                }

                // One line per channel, because that is what the economics are per.
                if ($product->economics->isEmpty()) {
                    yield array_merge($base, array_fill(0, 13, null));

                    continue;
                }

                foreach ($product->economics as $e) {
                    yield array_merge($base, [
                        $e->channel->label(), Currency::code($e->currency),
                        $e->rsp_ex_vat, $e->platform_fees_pct, $e->product_cost,
                        $e->marketing, $e->opex, $e->packaging,
                        $e->net_receivable, $e->cogs, $e->profit, $e->profit_pct, $e->margin_pct,
                    ]);
                }
            }
        };

        return CsvExport::stream('master-catalog', $headers, $rows(), [0, 11],
            ['OperON — Master catalog',
                $money ? 'Includes unit economics (Admin, PIN verified)' : 'Catalog only — no cost or margin',
                'Cost basis: '.config('operon.cost_basis').' supplier price (§S interim rule)']);
    }
}
