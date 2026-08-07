<?php

namespace App\Services\Import;

use App\Enums\Marketplace;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Support\Barcode;

/**
 * Resolves a file's own product id to a catalog product (§C key resolution, M9).
 *
 * Every M9 file names products a different way, and only one of them names them the way
 * the catalog does:
 *
 *   Amazon sell-out / inventory   ASIN                → product_identifiers (amazon)
 *   DFS sell-out / inventory      ASIN                → product_identifiers (amazon)
 *   Noon stock (SOH tab)          NIN                 → product_identifiers (noon)
 *   Noon sell-out (Sellout L60)   BARCODE ONLY        → NIN via the workbook's own
 *                                                       Barcodes tab, then as above
 *
 * ═══ WHY BARCODE IS A BRIDGE AND NEVER A KEY ═══
 *
 * §B is explicit that barcodes must never link products across platforms: they are
 * re-used, mistyped and mangled by Excel, and one wrong match pushes a wrong cost into
 * every figure that SKU touches. So the barcode is used to reach a NIN — inside a single
 * Noon workbook, where Noon itself supplied the mapping — and the NIN is what resolves.
 *
 * The barcode fallback below exists only for rows the workbook's own map does not cover,
 * it matches on the NORMALISED digits, and a barcode matching more than one product is
 * treated as no match rather than a coin toss.
 *
 * NOTHING IS EVER DROPPED. A row whose key the catalog has never seen resolves to null,
 * is stored with `is_unmatched`, and links itself when the master sheet catches up (§K).
 * Those rows are what the Master screen's fix list is built from.
 */
class SkuResolver
{
    /** @var array<string, ?int> marketplace|sku => product id */
    private array $byIdentifier = [];

    /** @var array<string, ?int>|null normalised barcode => product id, or null if ambiguous */
    private ?array $byBarcode = null;

    /** @var array<string, string> normalised barcode => NIN, from a Noon workbook's own map */
    private array $barcodeToNin = [];

    /** Teach the resolver a workbook's own barcode → NIN map (Noon's "Barcodes" tab). */
    public function learnBarcodeMap(array $map): void
    {
        foreach ($map as $barcode => $nin) {
            $key = Barcode::key($barcode);

            if ($key !== null && filled($nin)) {
                $this->barcodeToNin[$key] = (string) $nin;
            }
        }
    }

    /** The NIN this barcode belongs to, according to the workbook that supplied the map. */
    public function ninForBarcode(string|int|float|null $barcode): ?string
    {
        $key = Barcode::key($barcode);

        return $key === null ? null : ($this->barcodeToNin[$key] ?? null);
    }

    /** Straight identifier lookup, memoised — these files run to thousands of rows. */
    public function byIdentifier(Marketplace $marketplace, ?string $skuId): ?int
    {
        if (blank($skuId)) {
            return null;
        }

        $cacheKey = $marketplace->value.'|'.$skuId;

        return $this->byIdentifier[$cacheKey]
            ??= ProductIdentifier::resolveProductId($marketplace, $skuId);
    }

    /**
     * Resolve, trying the identifier first and the barcode only as a fallback.
     *
     * @return array{0: ?int, 1: ?string} the product id, and how it was found
     *                                    ('identifier' | 'barcode' | null)
     */
    public function resolve(Marketplace $marketplace, ?string $skuId, string|int|float|null $barcode = null): array
    {
        $productId = $this->byIdentifier($marketplace, $skuId);

        if ($productId !== null) {
            return [$productId, 'identifier'];
        }

        $viaBarcode = $this->byBarcode($barcode);

        return $viaBarcode === null ? [null, null] : [$viaBarcode, 'barcode'];
    }

    /**
     * A product whose barcode matches, if EXACTLY ONE does.
     *
     * Built once, in one query, because doing it per row over a 6,000-row sell-out feed
     * is thousands of round trips for an answer that cannot change mid-import.
     */
    public function byBarcode(string|int|float|null $barcode): ?int
    {
        $key = Barcode::key($barcode);

        if ($key === null) {
            return null;
        }

        $this->byBarcode ??= $this->buildBarcodeIndex();

        return $this->byBarcode[$key] ?? null;
    }

    /** @return array<string, ?int> */
    private function buildBarcodeIndex(): array
    {
        $index = [];
        $ambiguous = [];

        $add = function (?string $barcode, ?int $productId) use (&$index, &$ambiguous) {
            $key = Barcode::key($barcode);

            if ($key === null || $productId === null || isset($ambiguous[$key])) {
                return;
            }

            if (isset($index[$key]) && $index[$key] !== $productId) {
                // Two products, one barcode. §B says never guess - so neither wins, and
                // the rows land on the fix list where somebody can say which is right.
                unset($index[$key]);
                $ambiguous[$key] = true;

                return;
            }

            $index[$key] = $productId;
        };

        Product::query()
            ->whereNotNull('barcode')
            ->select('id', 'barcode')
            ->cursor()
            ->each(fn (Product $p) => $add($p->barcode, $p->id));

        ProductIdentifier::query()
            ->whereNotNull('barcode')
            ->whereNotNull('product_id')
            ->select('product_id', 'barcode')
            ->cursor()
            ->each(fn (ProductIdentifier $i) => $add($i->barcode, (int) $i->product_id));

        return $index;
    }
}
