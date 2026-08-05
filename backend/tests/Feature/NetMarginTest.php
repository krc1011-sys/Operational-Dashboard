<?php

namespace Tests\Feature;

use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProductChannelEconomics;
use App\Models\ProductIdentifier;
use App\Models\PurchaseOrder;
use App\Services\Margin\NetMarginEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The §S net-margin arithmetic.
 *
 * The worked example is a real row out of the merged master (BD06422853 on Amazon
 * Retail), with the sheet's own answers as the expected values — so this suite fails if
 * the engine ever stops reproducing the spreadsheet the business already trusts.
 */
class NetMarginTest extends TestCase
{
    use RefreshDatabase;

    /** Each call gets its own product, so a test can build two and compare them. */
    private int $sequence = 0;

    private function economics(array $overrides = []): ProductChannelEconomics
    {
        $product = Product::create([
            'company_product_code' => 'BD06422853-'.(++$this->sequence),
            'name' => 'Barcode Label - Direct Thermal',
            'brand' => 'Brandsfinity',
            'product_cost' => 18,
        ]);

        return ProductChannelEconomics::create(array_merge([
            'product_id' => $product->id,
            'channel' => 'amazon_retail',
            'rsp_with_vat' => 36.25,
            'rsp_ex_vat' => 34.523809523809526,
            // Amazon vendor terms: invoice is 90.19% of retail, we bank 78% of invoice.
            'invoice_pct_of_rsp' => 0.9019,
            'net_pct_of_invoice' => 0.78,
            // Seller-Central fees, present in the file and never applicable to a vendor.
            // Non-zero here on purpose: the engine must ignore them.
            'fulfilment_fee' => 5, 'referral_fee' => 5, 'storage_fee' => 5,
            'category_fee' => 5, 'other_fee' => 5, 'platform_fees_pct' => 0.99,
            'product_cost' => 18,
            'marketing' => 1.5568511904761906,
            'opex' => 1.8215158928571429,
            'packaging' => 0.93,
            'other_misc' => 0,
            'currency' => 'AED',
        ], $overrides));
    }

    /** The real row, against the figures the sheet itself produces (its columns N and T). */
    public function test_it_reproduces_the_sheets_own_answer(): void
    {
        $result = NetMarginEngine::compute($this->economics());

        // Column N: RSP ex VAT x 0.9019.
        $this->assertEqualsWithDelta(31.1370, $result['invoice_value'], 0.001);
        // Column T: invoice x 0.78.
        $this->assertEqualsWithDelta(24.2869, $result['net_receivable'], 0.001);
        $this->assertEqualsWithDelta(22.3084, $result['cogs'], 0.001);
        $this->assertEqualsWithDelta(1.9785, $result['profit'], 0.001);
        $this->assertEqualsWithDelta(0.088689, $result['profit_pct'], 0.0001);
        $this->assertEqualsWithDelta(0.081464, $result['margin_pct'], 0.0001);
    }

    /** Margin is a share of revenue, profit % is a markup on cost. Never the same number. */
    public function test_profit_percent_and_margin_percent_are_different_questions(): void
    {
        $result = NetMarginEngine::compute($this->economics());

        $this->assertGreaterThan($result['margin_pct'], $result['profit_pct'],
            'markup on cost is always the larger of the two');
    }

    public function test_cogs_is_cost_plus_marketing_opex_packaging_and_misc(): void
    {
        $economics = $this->economics([
            'product_cost' => 10, 'marketing' => 1, 'opex' => 2,
            'packaging' => 3, 'other_misc' => 4,
        ]);

        $this->assertSame(20.0, NetMarginEngine::cogs($economics));
    }

    public function test_vat_is_stripped_from_the_retail_price(): void
    {
        $economics = $this->economics(['rsp_with_vat' => 105, 'rsp_ex_vat' => null]);

        $this->assertEqualsWithDelta(100.0, NetMarginEngine::rspExVat($economics), 0.001);
    }

    /**
     * The packaging materials in the real file: things we buy and never sell. They have
     * a cost and no selling price, and "0% margin" would read as breaking even when the
     * truth is that the question does not apply.
     */
    public function test_something_we_buy_but_never_sell_has_no_margin_rather_than_zero(): void
    {
        $result = NetMarginEngine::compute($this->economics([
            'rsp_with_vat' => 0, 'rsp_ex_vat' => 0, 'platform_fees_pct' => 0,
            'product_cost' => 0.76, 'marketing' => 0, 'opex' => 0, 'packaging' => 0,
        ]));

        $this->assertNull($result['net_receivable']);
        $this->assertNull($result['profit']);
        $this->assertNull($result['margin_pct']);
        $this->assertSame(0.76, $result['cogs'], 'the cost is still known');
    }

    public function test_a_zero_cost_row_does_not_divide_by_zero(): void
    {
        $result = NetMarginEngine::compute($this->economics([
            'product_cost' => 0, 'marketing' => 0, 'opex' => 0, 'packaging' => 0, 'other_misc' => 0,
        ]));

        $this->assertNull($result['profit_pct'], 'no cost means no markup to express');
        $this->assertNotNull($result['margin_pct']);
    }

    public function test_a_loss_making_product_reports_a_negative_margin(): void
    {
        $result = NetMarginEngine::compute($this->economics(['product_cost' => 100]));

        $this->assertLessThan(0, $result['profit']);
        $this->assertLessThan(0, $result['margin_pct']);
    }

    /**
     * THE correction this model exists for. We are a vendor, not a Seller-Central
     * seller: the five fee columns in the sheet are not ours to pay and must never be
     * deducted. The fixture sets all of them, plus a 99% blended rate, precisely so that
     * anything reading them would produce a wildly wrong answer.
     */
    public function test_seller_central_fees_are_never_deducted(): void
    {
        $withFees = NetMarginEngine::compute($this->economics());
        $withoutFees = NetMarginEngine::compute($this->economics([
            'fulfilment_fee' => 0, 'referral_fee' => 0, 'storage_fee' => 0,
            'category_fee' => 0, 'other_fee' => 0, 'platform_fees_pct' => 0,
        ]));

        $this->assertSame($withFees['net_receivable'], $withoutFees['net_receivable']);
        $this->assertSame($withFees['profit'], $withoutFees['profit']);
    }

    /** The two steps are separate and both bite. */
    public function test_the_front_margin_sets_the_invoice_and_the_back_margin_what_we_bank(): void
    {
        $result = NetMarginEngine::compute($this->economics([
            'rsp_ex_vat' => 100, 'rsp_with_vat' => 105,
            'invoice_pct_of_rsp' => 0.9, 'net_pct_of_invoice' => 0.8,
        ]));

        $this->assertEqualsWithDelta(90.0, $result['invoice_value'], 0.001);
        $this->assertEqualsWithDelta(72.0, $result['net_receivable'], 0.001);
    }

    /** Noon's front margin is 2%, not Amazon's 9.81%. */
    public function test_noon_takes_a_smaller_front_margin_than_amazon(): void
    {
        $amazon = NetMarginEngine::compute($this->economics([
            'rsp_ex_vat' => 100, 'channel' => 'amazon_retail',
            'invoice_pct_of_rsp' => 0.9019, 'net_pct_of_invoice' => 0.78,
        ]));
        $noon = NetMarginEngine::compute($this->economics([
            'rsp_ex_vat' => 100, 'channel' => 'noon_retail',
            'invoice_pct_of_rsp' => 0.98, 'net_pct_of_invoice' => 0.78,
        ]));

        $this->assertEqualsWithDelta(90.19, $amazon['invoice_value'], 0.001);
        $this->assertEqualsWithDelta(98.0, $noon['invoice_value'], 0.001);
        $this->assertGreaterThan($amazon['net_receivable'], $noon['net_receivable']);
    }

    /**
     * The 151 Noon food rows in the real file bank 0.80 of the invoice, not 0.78. The
     * rate is stored per row precisely so this is honoured rather than flattened.
     */
    public function test_a_rows_own_rate_beats_the_channel_default(): void
    {
        $standard = NetMarginEngine::compute($this->economics([
            'rsp_ex_vat' => 100, 'channel' => 'noon_retail',
            'invoice_pct_of_rsp' => 0.98, 'net_pct_of_invoice' => 0.78,
        ]));
        $food = NetMarginEngine::compute($this->economics([
            'rsp_ex_vat' => 100, 'channel' => 'noon_retail',
            'invoice_pct_of_rsp' => 0.98, 'net_pct_of_invoice' => 0.80,
        ]));

        $this->assertEqualsWithDelta(76.44, $standard['net_receivable'], 0.001);
        $this->assertEqualsWithDelta(78.40, $food['net_receivable'], 0.001);
    }

    /** A row the file never spoke about falls back to its channel's standard terms. */
    public function test_a_row_with_no_stated_rates_uses_the_channel_defaults(): void
    {
        $economics = $this->economics([
            'rsp_ex_vat' => 100, 'channel' => 'amazon_retail',
            'invoice_pct_of_rsp' => null, 'net_pct_of_invoice' => null,
        ]);

        $this->assertSame(0.9019, NetMarginEngine::invoicePctOfRsp($economics));
        $this->assertSame(0.78, NetMarginEngine::netPctOfInvoice($economics));
    }

    /** For cross-checking against the sheet's own "Platform Total Fees %" column. */
    public function test_the_total_marketplace_take_is_reported(): void
    {
        $amazon = $this->economics(['invoice_pct_of_rsp' => 0.9019, 'net_pct_of_invoice' => 0.78]);
        $noon = $this->economics(['invoice_pct_of_rsp' => 0.98, 'net_pct_of_invoice' => 0.78]);

        $this->assertEqualsWithDelta(0.296518, NetMarginEngine::marketplaceTakePct($amazon), 0.000001);
        $this->assertEqualsWithDelta(0.2356, NetMarginEngine::marketplaceTakePct($noon), 0.000001);
    }

    /** Recomputing the same inputs twice must not move the answer. */
    public function test_recomputing_changes_nothing(): void
    {
        $economics = $this->economics();
        NetMarginEngine::apply($economics)->save();
        $first = $economics->fresh()->profit;

        NetMarginEngine::recomputeAll();

        $this->assertSame($first, $economics->fresh()->profit);
    }

    /** The engine falls back to the product's canonical cost when the row has none. */
    public function test_it_falls_back_to_the_products_own_cost(): void
    {
        $economics = $this->economics(['product_cost' => null]);
        $economics->setRelation('product', $economics->product);

        $this->assertEqualsWithDelta(22.3084, NetMarginEngine::cogs($economics), 0.001);
    }

    /**
     * Platform fees are deliberately left out of a PO's cost stack: a PO is wholesale,
     * so the marketplace is buying from us rather than charging us to sell.
     */
    public function test_a_po_cost_stack_excludes_platform_fees(): void
    {
        $economics = $this->economics();

        $this->assertSame(
            NetMarginEngine::cogs($economics),
            NetMarginEngine::poCostPerUnit($economics),
        );
    }

    // --- PO-level P&L (§S) -------------------------------------------------

    private function poWithLines(array $lines): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'marketplace' => 'amazon', 'po_number' => 'PO'.(++$this->sequence), 'channel' => 'amazon_retail',
        ]);

        foreach ($lines as $line) {
            PoLine::create(array_merge([
                'purchase_order_id' => $po->id, 'marketplace' => 'amazon',
                'po_number' => $po->po_number, 'channel' => 'amazon_retail', 'currency' => 'AED',
            ], $line));
        }

        return $po;
    }

    /**
     * A PO is the invoice, so the front margin is already in its unit cost. What still
     * has to come off is the BACK margin - we bill 3,000 and bank 2,340.
     *
     * Treating the billed figure as money received is the mistake this guards against:
     * it would report 769.16 profit on a PO that actually makes 109.16.
     */
    public function test_a_po_banks_the_invoice_less_the_back_margin(): void
    {
        $economics = $this->economics();           // COGS 22.3084 per unit
        $identifier = ProductIdentifier::create([
            'product_id' => $economics->product_id, 'marketplace' => 'amazon', 'sku_id' => 'B0TEST00001',
        ]);

        $po = $this->poWithLines([[
            'sku_id' => $identifier->sku_id, 'product_id' => $economics->product_id,
            'qty_shipped' => 100, 'unit_cost' => 30,
        ]]);

        $result = NetMarginEngine::forPurchaseOrder($po);

        $this->assertEqualsWithDelta(3000.0, $result['billed'], 0.01, 'what we invoiced');
        $this->assertEqualsWithDelta(2340.0, $result['net_receivable'], 0.01, 'what we bank: 78% of it');
        $this->assertEqualsWithDelta(660.0, $result['back_margin_deducted'], 0.01, "the marketplace's cut");
        $this->assertEqualsWithDelta(2230.84, $result['cost'], 0.01);
        $this->assertEqualsWithDelta(109.16, $result['profit'], 0.01);
        // Margin is against what we bank, not what we billed.
        $this->assertEqualsWithDelta(4.67, $result['margin_pct'], 0.01);
        $this->assertTrue($result['coverage']['complete']);
    }

    /**
     * The failure this guards against: a line we cannot cost must not be counted as
     * revenue, or it reads as pure profit and flatters the whole PO.
     */
    public function test_a_line_with_no_catalog_cost_is_left_out_of_the_profit_and_reported(): void
    {
        $economics = $this->economics();

        $po = $this->poWithLines([
            ['sku_id' => 'B0TEST00001', 'product_id' => $economics->product_id,
                'qty_shipped' => 100, 'unit_cost' => 30],
            // Nothing in the catalog knows this one.
            ['sku_id' => 'B0UNKNOWN01', 'product_id' => null,
                'qty_shipped' => 50, 'unit_cost' => 40],
        ]);

        $result = NetMarginEngine::forPurchaseOrder($po);

        $this->assertEqualsWithDelta(5000.0, $result['billed'], 0.01, 'the whole PO billed 5,000');
        $this->assertEqualsWithDelta(3000.0, $result['invoice_costed'], 0.01, 'only 3,000 of it can be costed');
        $this->assertEqualsWithDelta(109.16, $result['profit'], 0.01, 'the uncosted line adds no profit');

        $this->assertFalse($result['coverage']['complete']);
        $this->assertSame(1, $result['coverage']['lines_uncosted']);
        $this->assertSame(50, $result['coverage']['units_uncosted']);
    }

    /** Units that never shipped were never billed, so they are not in the P&L. */
    public function test_unshipped_units_are_not_counted(): void
    {
        $economics = $this->economics();

        $po = $this->poWithLines([[
            'sku_id' => 'B0TEST00001', 'product_id' => $economics->product_id,
            'qty_accepted' => 100, 'qty_shipped' => 0, 'unit_cost' => 30,
        ]]);

        $result = NetMarginEngine::forPurchaseOrder($po);

        $this->assertSame(0.0, $result['billed']);
        $this->assertNull($result['profit']);
        $this->assertFalse($result['coverage']['complete']);
    }

    /** Anything built on the interim cost rule says which rule it used (§S). */
    public function test_the_result_carries_the_cost_basis_it_used(): void
    {
        $result = NetMarginEngine::forPurchaseOrder($this->poWithLines([]));

        $this->assertSame(config('operon.cost_basis'), $result['cost_basis']);
    }
}
