<?php

namespace App\Services\Reporting;

use App\Enums\Channel;
use App\Models\Delivery;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Services\Spreadsheet\CellValue;
use App\Services\Spreadsheet\Workbook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * The §M self-serve filter set, built once and used by every screen.
 *
 * The blueprint's cross-cutting rule is that the team must be able to build their own
 * reports without asking anybody: "every data tab carries a rich, consistent filter set".
 * Consistent is the operative word - so there is exactly ONE of these, and each screen
 * says which of its fields it shows rather than growing its own.
 *
 * Fields (§M): date range · channel · FC · brand · category · status · PO number ·
 * ASIN/NIN/barcode/title search · a pasted or uploaded list of ASINs/NINs · group-by.
 *
 * Two notes on behaviour that are easy to get wrong:
 *
 *  - The DATE RANGE means the PO's order date, because that is the one date §L calls
 *    stable and says time-based reports should anchor on. The one exception is the
 *    Shipments screen, which is about deliveries, so there it means the delivery date.
 *  - The BULK LIST can be thousands of ASINs, which will not fit in a URL. It is parsed
 *    once, kept in the session, and only a short key travels in the query string - so
 *    paging and exporting keep the same list without re-pasting it.
 */
class FilterSet
{
    /** Enough for a very long paste without letting one request eat the server. */
    public const MAX_IDENTIFIERS = 5000;

    public const GROUP_NONE = 'none';

    public const GROUP_SKU = 'sku';

    public const GROUP_BRAND = 'brand';

    public const GROUP_CATEGORY = 'category';

    /**
     * @param  Channel[]  $channels
     * @param  string[]  $skus
     */
    public function __construct(
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly array $channels = [],
        public readonly ?string $fc = null,
        public readonly ?string $brand = null,
        public readonly ?string $category = null,
        public readonly ?string $status = null,
        public readonly ?string $po = null,
        public readonly ?string $search = null,
        public readonly array $skus = [],
        public readonly ?string $skuKey = null,
        public readonly string $groupBy = self::GROUP_NONE,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string'],
            'fc' => ['nullable', 'string', 'max:40'],
            'brand' => ['nullable', 'string', 'max:190'],
            'category' => ['nullable', 'string', 'max:190'],
            'status' => ['nullable', 'string', 'max:30'],
            'po' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:190'],
            'sku_list' => ['nullable', 'string', 'max:120000'],
            'sku_file' => ['nullable', 'file', 'max:5120'],
            'sku_key' => ['nullable', 'string', 'max:40'],
            'group_by' => ['nullable', 'in:none,sku,brand,category'],
        ]);

        [$skus, $skuKey] = self::resolveIdentifiers($request, $validated);

        return new self(
            from: filled($validated['from'] ?? null) ? Carbon::parse($validated['from'])->startOfDay() : null,
            to: filled($validated['to'] ?? null) ? Carbon::parse($validated['to'])->endOfDay() : null,
            channels: collect($validated['channels'] ?? [])
                ->map(fn ($c) => Channel::tryFrom($c))
                ->filter()
                ->values()
                ->all(),
            fc: self::clean($validated['fc'] ?? null),
            brand: self::clean($validated['brand'] ?? null),
            category: self::clean($validated['category'] ?? null),
            status: self::clean($validated['status'] ?? null),
            po: self::clean($validated['po'] ?? null),
            search: self::clean($validated['search'] ?? null),
            skus: $skus,
            skuKey: $skuKey,
            groupBy: $validated['group_by'] ?? self::GROUP_NONE,
        );
    }

    // --- Applying -------------------------------------------------------------

    /**
     * Narrow a query over PO lines - the spine most screens read from.
     *
     * @param  Builder<PoLine>  $query
     */
    public function applyToLines(Builder $query): Builder
    {
        if ($this->channels !== []) {
            $query->whereIn('channel', array_map(fn (Channel $c) => $c->value, $this->channels));
        }

        if ($this->fc !== null) {
            $query->where('ship_to_fc', $this->fc);
        }

        if ($this->status !== null) {
            $query->where('line_state', $this->status);
        }

        if ($this->po !== null) {
            $query->where('po_number', 'like', '%'.$this->po.'%');
        }

        if ($this->search !== null) {
            $query->search($this->search);
        }

        if ($this->skus !== []) {
            $query->whereIn('sku_id', $this->skus);
        }

        // The date range is the PO's order date (§L), which lives on the PO header.
        if ($this->from !== null || $this->to !== null) {
            $query->whereHas('purchaseOrder', fn (Builder $po) => $this->applyDateRange($po, 'order_date'));
        }

        $this->applyProductFilters($query);

        return $query;
    }

    /**
     * Narrow a query over deliveries. Here the date range means the delivery's own date,
     * because that is what the screen is about; everything that lives on the lines is
     * matched through them.
     *
     * @param  Builder<Delivery>  $query
     */
    public function applyToDeliveries(Builder $query): Builder
    {
        if ($this->channels !== []) {
            $query->whereIn('channel', array_map(fn (Channel $c) => $c->value, $this->channels));
        }

        if ($this->fc !== null) {
            $query->where('fc_code', $this->fc);
        }

        // The delivery's date: the real one when we have it, otherwise the planned one.
        if ($this->from !== null) {
            $query->whereRaw('COALESCE(delivered_on, planned_date) >= ?', [$this->from->toDateString()]);
        }

        if ($this->to !== null) {
            $query->whereRaw('COALESCE(delivered_on, planned_date) <= ?', [$this->to->toDateString()]);
        }

        if ($this->po !== null || $this->skus !== [] || $this->search !== null
            || $this->brand !== null || $this->category !== null) {
            $query->whereHas('lines', function (Builder $lines) {
                if ($this->po !== null) {
                    $lines->where('po_number', 'like', '%'.$this->po.'%');
                }

                if ($this->skus !== []) {
                    $lines->whereIn('sku_id', $this->skus);
                }

                if ($this->search !== null) {
                    $lines->where(fn (Builder $q) => $q
                        ->where('sku_id', 'like', '%'.$this->search.'%')
                        ->orWhere('title', 'like', '%'.$this->search.'%')
                        ->orWhere('po_number', 'like', '%'.$this->search.'%'));
                }

                $this->applyProductFilters($lines);
            });
        }

        return $query;
    }

    /**
     * Narrow a query over sell-out rows (M9).
     *
     * The date range means the period the row COVERS, and it is an overlap test rather
     * than a containment one. That matters because Amazon's sell-out arrives as a single
     * row spanning its whole reporting window: asking for "July" and testing containment
     * would drop a row covering June to August entirely, and the screen would report
     * that Amazon sold nothing.
     *
     * @param  Builder<SelloutRow>  $query
     */
    public function applyToSellout(Builder $query): Builder
    {
        if ($this->channels !== []) {
            $query->whereIn('channel', array_map(fn (Channel $c) => $c->value, $this->channels));
        }

        if ($this->skus !== []) {
            $query->whereIn('sku_id', $this->skus);
        }

        if ($this->search !== null) {
            $query->where(fn (Builder $q) => $q
                ->where('sku_id', 'like', '%'.$this->search.'%')
                ->orWhere('title', 'like', '%'.$this->search.'%')
                ->orWhere('barcode', 'like', '%'.$this->search.'%'));
        }

        if ($this->from !== null) {
            $query->whereDate('period_end', '>=', $this->from);
        }

        if ($this->to !== null) {
            $query->whereDate('period_start', '<=', $this->to);
        }

        $this->applyProductFilters($query);

        return $query;
    }

    /**
     * Narrow a query over stock snapshots (M9).
     *
     * Deliberately NO date range: stock is a level, and every stock screen is about the
     * latest snapshot. Applying the report's date filter here would answer "what did we
     * hold in July", which is a different question from the one this data can answer,
     * and it would silently empty the screen for any range not covering the upload day.
     *
     * @param  Builder<InventorySnapshot>  $query
     */
    public function applyToInventory(Builder $query): Builder
    {
        if ($this->channels !== []) {
            $query->whereIn('channel', array_map(fn (Channel $c) => $c->value, $this->channels));
        }

        if ($this->skus !== []) {
            $query->whereIn('sku_id', $this->skus);
        }

        if ($this->search !== null) {
            $query->where(fn (Builder $q) => $q
                ->where('sku_id', 'like', '%'.$this->search.'%')
                ->orWhere('title', 'like', '%'.$this->search.'%')
                ->orWhere('barcode', 'like', '%'.$this->search.'%'));
        }

        $this->applyProductFilters($query);

        return $query;
    }

    /** The channels in play, or all of them when nothing is selected. @return Channel[] */
    public function activeChannels(): array
    {
        return $this->channels === [] ? Channel::cases() : $this->channels;
    }

    /**
     * Narrow a query over purchase orders.
     *
     * @param  Builder<PurchaseOrder>  $query
     */
    public function applyToPurchaseOrders(Builder $query): Builder
    {
        if ($this->channels !== []) {
            $query->whereIn('channel', array_map(fn (Channel $c) => $c->value, $this->channels));
        }

        if ($this->fc !== null) {
            $query->where('ship_to_fc', $this->fc);
        }

        if ($this->po !== null) {
            $query->where('po_number', 'like', '%'.$this->po.'%');
        }

        $this->applyDateRange($query, 'order_date');

        if ($this->search !== null || $this->skus !== [] || $this->brand !== null || $this->category !== null) {
            $query->whereHas('lines', function (Builder $lines) {
                if ($this->search !== null) {
                    $lines->search($this->search);
                }

                if ($this->skus !== []) {
                    $lines->whereIn('sku_id', $this->skus);
                }

                $this->applyProductFilters($lines);
            });
        }

        return $query;
    }

    /**
     * Brand and category live on the master catalog, so they can only match rows whose
     * SKU the catalog knows. Until the master sheet is loaded (M6) nothing is linked,
     * which is why the filter bar says so rather than offering an empty dropdown.
     */
    private function applyProductFilters(Builder $query): void
    {
        if ($this->brand === null && $this->category === null) {
            return;
        }

        $query->whereHas('product', function (Builder $product) {
            if ($this->brand !== null) {
                $product->where('brand', $this->brand);
            }

            if ($this->category !== null) {
                $product->where('category', $this->category);
            }
        });
    }

    private function applyDateRange(Builder $query, string $column): Builder
    {
        if ($this->from !== null) {
            $query->whereDate($column, '>=', $this->from);
        }

        if ($this->to !== null) {
            $query->whereDate($column, '<=', $this->to);
        }

        return $query;
    }

    // --- State ----------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->from !== null || $this->to !== null || $this->channels !== []
            || $this->fc !== null || $this->brand !== null || $this->category !== null
            || $this->status !== null || $this->po !== null || $this->search !== null
            || $this->skus !== [];
    }

    /** Everything needed to rebuild this filter from a link - paging, export, drill-down. */
    public function query(array $extra = []): array
    {
        return array_filter([
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'channels' => $this->channels === []
                ? null
                : array_map(fn (Channel $c) => $c->value, $this->channels),
            'fc' => $this->fc,
            'brand' => $this->brand,
            'category' => $this->category,
            'status' => $this->status,
            'po' => $this->po,
            'search' => $this->search,
            // The list itself stays in the session; only its key travels.
            'sku_key' => $this->skuKey,
            'group_by' => $this->groupBy === self::GROUP_NONE ? null : $this->groupBy,
        ] + $extra, fn ($v) => $v !== null && $v !== []);
    }

    /** What is switched on, in words - shown on screen and written into the export. */
    public function summary(): array
    {
        $parts = [];

        if ($this->from || $this->to) {
            $parts[] = 'dates '.($this->from?->toDateString() ?? 'any').' to '.($this->to?->toDateString() ?? 'any');
        }

        if ($this->channels !== []) {
            $parts[] = 'channel '.implode(' + ', array_map(fn (Channel $c) => $c->label(), $this->channels));
        }

        foreach (['FC' => $this->fc, 'brand' => $this->brand, 'category' => $this->category,
            'status' => $this->status, 'PO' => $this->po, 'search' => $this->search] as $label => $value) {
            if ($value !== null) {
                $parts[] = "{$label} {$value}";
            }
        }

        if ($this->skus !== []) {
            $parts[] = count($this->skus).' pasted identifier(s)';
        }

        return $parts;
    }

    // --- Options for the filter bar -------------------------------------------

    /** @return array<int, string> */
    public static function fulfilmentCentres(): array
    {
        return PoLine::query()
            ->whereNotNull('ship_to_fc')
            ->distinct()
            ->orderBy('ship_to_fc')
            ->pluck('ship_to_fc')
            ->all();
    }

    /** @return array<int, string> */
    public static function brands(): array
    {
        return Product::query()->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand')->all();
    }

    /** @return array<int, string> */
    public static function categories(): array
    {
        return Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
    }

    /** @return array<string, string> */
    public static function lineStates(): array
    {
        return [
            PoLine::STATE_NOT_BOOKED => 'Not yet booked',
            PoLine::STATE_SCHEDULED => 'Scheduled (booked into a delivery)',
            PoLine::STATE_DISPATCHED => 'Dispatched (shipped)',
            PoLine::STATE_CANCELLED => 'Cancelled',
        ];
    }

    // --- The bulk ASIN/NIN list (§M, §R) --------------------------------------

    /**
     * Work out the list of identifiers for this request: freshly pasted, freshly
     * uploaded, or carried over from an earlier request by its key.
     *
     * @return array{0: string[], 1: ?string}
     */
    private static function resolveIdentifiers(Request $request, array $validated): array
    {
        $raw = null;

        if ($request->hasFile('sku_file')) {
            $raw = self::readIdentifierFile($request->file('sku_file'));
        } elseif (filled($validated['sku_list'] ?? null)) {
            $raw = $validated['sku_list'];
        }

        if ($raw !== null) {
            $skus = self::parseIdentifiers($raw);

            if ($skus === []) {
                return [[], null];
            }

            // Too long for a URL, so it lives in the session and travels as a key.
            $key = Str::random(16);
            Session::put(self::sessionKey($key), $skus);

            return [$skus, $key];
        }

        $key = $validated['sku_key'] ?? null;

        if (filled($key)) {
            $stored = Session::get(self::sessionKey($key), []);

            if ($stored !== []) {
                return [$stored, $key];
            }
        }

        return [[], null];
    }

    private static function sessionKey(string $key): string
    {
        return 'operon.sku_filter.'.$key;
    }

    /**
     * Split a pasted block into identifiers. People paste a column out of Excel, a
     * comma-separated line, or one per row - all three work, and duplicates collapse.
     *
     * @return string[]
     */
    public static function parseIdentifiers(string $raw): array
    {
        $parts = preg_split('/[\s,;|]+/', trim($raw)) ?: [];

        return collect($parts)
            ->map(fn ($p) => strtoupper(trim($p)))
            ->filter(fn ($p) => $p !== '')
            ->unique()
            ->take(self::MAX_IDENTIFIERS)
            ->values()
            ->all();
    }

    /**
     * Read an uploaded list. A spreadsheet is read down its first column - which is what
     * you get by copying a column of ASINs out of Excel and saving it - and anything
     * else is treated as plain text.
     */
    private static function readIdentifierFile($file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['xlsx', 'xls'], true)) {
            return (string) file_get_contents($file->getRealPath());
        }

        $workbook = Workbook::open($file->getRealPath());

        try {
            $sheet = $workbook->sheet($workbook->sheetNames()[0]);
            $values = [];

            for ($row = 1; $row <= 5000; $row++) {
                $value = CellValue::asText($sheet->cellAt($row, 1));

                if ($value !== null) {
                    $values[] = $value;
                }
            }

            return implode("\n", $values);
        } finally {
            $workbook->close();
        }
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
