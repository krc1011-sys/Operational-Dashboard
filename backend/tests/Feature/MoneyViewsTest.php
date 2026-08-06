<?php

namespace Tests\Feature;

use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProductChannelEconomics;
use App\Models\ProductIdentifier;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Margin\NetMarginEngine;
use App\Services\Margin\ProfitAndLoss;
use App\Services\Margin\SkuMargin;
use App\Support\MoneyGate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * M7 — the money views (§Profitability).
 *
 * Three things are being defended here, and they are the three things that would each on
 * their own make the screens worse than useless:
 *
 *   1. THE P&L ADDS UP. Its lines are the engine's own figures and they total its bottom
 *      line to the fils. A statement that does not add up is not believed, correctly.
 *   2. "BOTH" IS REVENUE-WEIGHTED. A simple mean of the channel percentages would have us
 *      drop products that are doing fine, so the tests pick numbers where the two answers
 *      are far apart and assert we give the right one.
 *   3. THE GATE SPLITS THE RIGHT WAY. Order value - how big the order is - stays open to
 *      every role with no PIN. Margin - what we make on it - needs `view-margin` AND the
 *      PIN, every time, on every screen that shows it.
 */
class MoneyViewsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operon.money_pin' => '4321']);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // --- fixtures ---------------------------------------------------------

    private function admin(): User
    {
        return tap(User::factory()->create())->assignRole('Admin');
    }

    /** An admin who has already entered the PIN. */
    private function unlockedAdmin(): User
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/money-pin', ['pin' => '4321']);

        return $admin;
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'company_product_code' => 'BD-'.(++$this->sequence),
            'name' => 'Test product '.$this->sequence,
            'brand' => 'Brandsfinity',
            'category' => 'Home',
            'product_cost' => 18,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * One channel's economics. Rates default to the real Amazon vendor terms; every
     * figure is an INPUT, because the engine's own outputs are what is under test.
     */
    private function economics(Product $product, string $channel, array $overrides = []): ProductChannelEconomics
    {
        $row = ProductChannelEconomics::create(array_merge([
            'product_id' => $product->id,
            'channel' => $channel,
            'rsp_ex_vat' => 34.523809523809526,
            'invoice_pct_of_rsp' => 0.9019,
            'net_pct_of_invoice' => 0.78,
            'product_cost' => 18,
            'marketing' => 1.5568511904761906,
            'opex' => 1.8215158928571429,
            'packaging' => 0.93,
            'other_misc' => 0,
            'currency' => 'AED',
        ], $overrides));

        return tap(NetMarginEngine::apply($row))->save();
    }

    private function poWithLines(array $lines, ?string $poNumber = null): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'marketplace' => 'amazon',
            'po_number' => $poNumber ?? 'PO'.(++$this->sequence),
            'channel' => 'amazon_retail',
            'order_date' => now()->subDays(5),
            'ship_to_fc' => 'DXB7',
        ]);

        foreach ($lines as $line) {
            PoLine::create(array_merge([
                'purchase_order_id' => $po->id, 'marketplace' => 'amazon',
                'po_number' => $po->po_number, 'channel' => 'amazon_retail', 'currency' => 'AED',
            ], $line));
        }

        return $po;
    }

    /** A PO whose one line is fully costable — the simplest complete P&L. */
    private function costablePo(): PurchaseOrder
    {
        $product = $this->product();
        $this->economics($product, 'amazon_retail');
        ProductIdentifier::create([
            'product_id' => $product->id, 'marketplace' => 'amazon', 'sku_id' => 'B0PNL00001',
        ]);

        return $this->poWithLines([[
            'sku_id' => 'B0PNL00001', 'product_id' => $product->id,
            'qty_requested' => 100, 'qty_accepted' => 100, 'qty_net_accepted' => 100,
            'qty_shipped' => 100, 'unit_cost' => 30,
        ]]);
    }

    /** A PO with one costable line and one SKU the catalog has never heard of. */
    private function partlyCostablePo(): PurchaseOrder
    {
        $product = $this->product();
        $this->economics($product, 'amazon_retail');
        ProductIdentifier::create([
            'product_id' => $product->id, 'marketplace' => 'amazon', 'sku_id' => 'B0PNL00010',
        ]);

        return $this->poWithLines([
            ['sku_id' => 'B0PNL00010', 'product_id' => $product->id, 'qty_shipped' => 100, 'unit_cost' => 30],
            ['sku_id' => 'B0STRANGER', 'product_id' => null, 'qty_shipped' => 20, 'unit_cost' => 25],
        ]);
    }

    // =====================================================================
    // 1. THE PO-LEVEL P&L
    // =====================================================================

    /**
     * The blueprint's own example shape: "billed 10,000 → net 1,000 = 10%".
     *
     * 100 units billed at 30 = 3,000. The marketplace keeps 22% of the invoice, so we
     * bank 2,340. Our cost is 22.3084 a unit, 2,230.84. Profit 109.16, margin 4.66% of
     * what we BANK — not of what we billed, which would read 3.64% and be the wrong
     * question answered confidently. The difference between those two denominators is
     * the correction M6 made and this screen inherits.
     */
    public function test_the_statement_walks_revenue_down_to_net_profit(): void
    {
        $pnl = ProfitAndLoss::forPurchaseOrder($this->costablePo());

        $this->assertTrue($pnl['costable']);
        $this->assertEqualsWithDelta(3000.0, $pnl['billed'], 0.01);
        $this->assertEqualsWithDelta(2340.0, $pnl['net_receivable'], 0.01);
        $this->assertEqualsWithDelta(2230.84, $pnl['cost'], 0.01);
        $this->assertEqualsWithDelta(109.16, $pnl['profit'], 0.01);
        $this->assertSame(4.66, $pnl['margin_pct']);

        $labels = array_column($pnl['lines'], 'label');

        $this->assertSame('Invoiced to the marketplace', $labels[0]);
        $this->assertSame('Net profit', end($labels));
        $this->assertContains('Net receivable', $labels);
    }

    /**
     * THE PROPERTY THAT MAKES THE STATEMENT WORTH PRINTING: its lines add up to its
     * bottom line. Every deduction, in order, taken off the revenue, lands on the profit.
     */
    public function test_the_statement_lines_add_up_to_the_bottom_line(): void
    {
        $pnl = ProfitAndLoss::forPurchaseOrder($this->costablePo());

        $running = 0.0;
        $checkpoints = 0;

        foreach ($pnl['lines'] as $line) {
            // Subtotals and the result RESTATE the running figure rather than adding to
            // it, so each is a checkpoint on every line above it.
            if (in_array($line['kind'], [ProfitAndLoss::SUBTOTAL, ProfitAndLoss::RESULT], true)) {
                $this->assertEqualsWithDelta((float) $line['amount'], $running, 0.011,
                    "the lines above '{$line['label']}' do not add up to it");
                $checkpoints++;

                continue;
            }

            $running += (float) $line['amount'];
        }

        $this->assertGreaterThanOrEqual(2, $checkpoints, 'net receivable and net profit are both checked');
    }

    /** The itemised deductions are the same money as the total, not a second opinion. */
    public function test_the_cost_breakdown_totals_the_cost(): void
    {
        $result = NetMarginEngine::forPurchaseOrder($this->costablePo());

        $this->assertEqualsWithDelta($result['cost'], array_sum($result['cost_breakdown']), 0.001);
        $this->assertSame(NetMarginEngine::costComponents(), array_keys($result['cost_breakdown']));
    }

    /**
     * A cost the master sheet has no figure for gets its line anyway, reading 0 and
     * labelled — a P&L with a cost line missing reads as a P&L with no such cost, which
     * is a different and much more flattering claim.
     */
    public function test_a_cost_with_no_data_yet_still_gets_its_own_labelled_line(): void
    {
        $product = $this->product();
        // Marketing and packaging deliberately absent, as they are for most of the file.
        $this->economics($product, 'amazon_retail', ['marketing' => 0, 'packaging' => 0]);
        ProductIdentifier::create([
            'product_id' => $product->id, 'marketplace' => 'amazon', 'sku_id' => 'B0PNL00002',
        ]);

        $po = $this->poWithLines([[
            'sku_id' => 'B0PNL00002', 'product_id' => $product->id,
            'qty_shipped' => 100, 'unit_cost' => 30,
        ]]);

        $pnl = ProfitAndLoss::forPurchaseOrder($po);

        $marketing = collect($pnl['lines'])->firstWhere('label', 'Less marketing');
        $packaging = collect($pnl['lines'])->firstWhere('label', 'Less packaging');
        $opex = collect($pnl['lines'])->firstWhere('label', 'Less opex');

        $this->assertNotNull($marketing, 'the marketing line must exist even at zero');
        $this->assertSame(0.0, (float) $marketing['amount']);
        $this->assertTrue($marketing['pending']);
        $this->assertStringContainsString(ProfitAndLoss::UNTIL_DATA_ADDED, $marketing['note']);

        $this->assertTrue($packaging['pending']);
        // OPEX has a figure, so it is NOT tagged - the tag is data-driven, not a blanket
        // "these three are always empty" that would go on lying once they fill in.
        $this->assertFalse($opex['pending']);

        $this->assertSame(['Marketing', 'Packaging', 'Other'], $pnl['pending']);
    }

    /** Those lines go live off the same inputs, with no code change. */
    public function test_a_cost_line_fills_in_by_itself_once_the_data_arrives(): void
    {
        $product = $this->product();
        $economics = $this->economics($product, 'amazon_retail', ['marketing' => 0]);
        ProductIdentifier::create([
            'product_id' => $product->id, 'marketplace' => 'amazon', 'sku_id' => 'B0PNL00003',
        ]);

        $po = $this->poWithLines([[
            'sku_id' => 'B0PNL00003', 'product_id' => $product->id,
            'qty_shipped' => 100, 'unit_cost' => 30,
        ]]);

        $this->assertContains('Marketing', ProfitAndLoss::forPurchaseOrder($po)['pending']);

        // The only thing that changes is the number in the sheet.
        $economics->marketing = 2.5;
        NetMarginEngine::apply($economics)->save();

        $after = ProfitAndLoss::forPurchaseOrder($po->fresh());

        $this->assertNotContains('Marketing', $after['pending']);
        $this->assertEqualsWithDelta(250.0, $after['cost_breakdown']['marketing'], 0.01);
    }

    /** An uncosted line is visible as a hole in the coverage, not folded into profit. */
    public function test_an_uncostable_line_is_reported_rather_than_counted(): void
    {
        $product = $this->product();
        $this->economics($product, 'amazon_retail');
        ProductIdentifier::create([
            'product_id' => $product->id, 'marketplace' => 'amazon', 'sku_id' => 'B0PNL00004',
        ]);

        $po = $this->poWithLines([
            ['sku_id' => 'B0PNL00004', 'product_id' => $product->id, 'qty_shipped' => 100, 'unit_cost' => 30],
            ['sku_id' => 'B0UNKNOWN9', 'product_id' => null, 'qty_shipped' => 50, 'unit_cost' => 40],
        ]);

        $pnl = ProfitAndLoss::forPurchaseOrder($po);

        $this->assertFalse($pnl['coverage']['complete']);
        $this->assertEqualsWithDelta(109.16, $pnl['profit'], 0.01, 'the uncosted line adds no profit');

        $labels = array_column($pnl['lines'], 'label');
        $this->assertContains('Less the part we cannot cost', $labels);
    }

    // =====================================================================
    // 2. SKU-LEVEL MARGIN — the revenue-weighted "Both"
    // =====================================================================

    /**
     * ═══ THE TEST THIS WHOLE FILE EXISTS FOR ═══
     *
     * "Both" is a revenue-weighted average, never a simple mean.
     *
     * Amazon: 100 units, 10.00 banked each, 3.00 profit each → 30% margin, 1,000 revenue.
     * Noon:   1 unit,    10.00 banked,      0.50 profit      →  5% margin,    10 revenue.
     *
     * Revenue-weighted:  (100x3.00 + 1x0.50) / (100x10 + 1x10) = 300.50/1010 = 29.75%
     * Simple mean:       (30 + 5) / 2                                        = 17.50%
     *
     * The gap is the whole point. A product making 29.75% is doing well; one making
     * 17.5% might be on the chopping block. The simple mean gets there because a single
     * unit of Noon is allowed to weigh as much as a hundred units of Amazon.
     */
    public function test_both_is_revenue_weighted_and_not_a_simple_mean(): void
    {
        $blend = SkuMargin::combine([
            ['net_receivable' => 10.0, 'profit' => 3.0, 'cogs' => 7.0, 'margin_pct' => 30.0, 'units' => 100],
            ['net_receivable' => 10.0, 'profit' => 0.5, 'cogs' => 9.5, 'margin_pct' => 5.0, 'units' => 1],
        ]);

        $this->assertEqualsWithDelta(29.75, $blend['margin_pct'], 0.01);
        $this->assertNotEqualsWithDelta(17.5, $blend['margin_pct'], 0.01,
            'a simple mean of 30% and 5% would be 17.5% - that is the mistake being guarded');
        $this->assertSame(SkuMargin::BASIS_SHIPPED, $blend['weight_basis']);
    }

    /**
     * The same rule with the weights the other way round. Revenue weighting is not a
     * thumb on the scale for the bigger percentage — it follows the money, wherever it is.
     */
    public function test_the_weighting_follows_the_money_not_the_bigger_percentage(): void
    {
        $blend = SkuMargin::combine([
            ['net_receivable' => 10.0, 'profit' => 3.0, 'cogs' => 7.0, 'margin_pct' => 30.0, 'units' => 1],
            ['net_receivable' => 10.0, 'profit' => 0.5, 'cogs' => 9.5, 'margin_pct' => 5.0, 'units' => 100],
        ]);

        // 300.50 → 53.00 of profit on 1,010 of revenue = 5.25%, not 17.5%.
        $this->assertEqualsWithDelta(5.25, $blend['margin_pct'], 0.01);
        $this->assertLessThan(17.5, $blend['margin_pct']);
    }

    /** Unit COSTS blend over units, not over money — the blueprint's own caveat. */
    public function test_unit_costs_are_unit_weighted(): void
    {
        $blend = SkuMargin::combine([
            ['net_receivable' => 100.0, 'profit' => 30.0, 'cogs' => 70.0, 'margin_pct' => 30.0, 'units' => 3],
            ['net_receivable' => 10.0, 'profit' => 0.5, 'cogs' => 9.5, 'margin_pct' => 5.0, 'units' => 1],
        ]);

        // (3 x 70 + 1 x 9.5) / 4 = 54.875. Weighted by REVENUE it would be 65.0.
        $this->assertEqualsWithDelta(54.875, $blend['cogs'], 0.001);
    }

    /**
     * With nothing shipped there is no recorded revenue to weight by, so the blend falls
     * back to one unit of each channel — still a revenue weighting, by each channel's own
     * per-unit receivable — and says so, because a weighting you cannot see is a number
     * you cannot check.
     */
    public function test_with_nothing_shipped_the_blend_weights_per_unit_and_says_so(): void
    {
        $blend = SkuMargin::combine([
            ['net_receivable' => 30.0, 'profit' => 9.0, 'cogs' => 21.0, 'margin_pct' => 30.0, 'units' => 0],
            ['net_receivable' => 10.0, 'profit' => 0.5, 'cogs' => 9.5, 'margin_pct' => 5.0, 'units' => 0],
        ]);

        // 9.50 of profit on 40.00 of revenue = 23.75%. A simple mean would say 17.5%.
        $this->assertSame(SkuMargin::BASIS_PER_UNIT, $blend['weight_basis']);
        $this->assertEqualsWithDelta(23.75, $blend['margin_pct'], 0.01);
    }

    /** A channel with nothing shipped earned nothing, so it carries no weight. */
    public function test_a_channel_with_no_shipped_units_does_not_pull_the_blend(): void
    {
        $blend = SkuMargin::combine([
            ['net_receivable' => 10.0, 'profit' => 3.0, 'cogs' => 7.0, 'margin_pct' => 30.0, 'units' => 100],
            ['net_receivable' => 10.0, 'profit' => -5.0, 'cogs' => 15.0, 'margin_pct' => -50.0, 'units' => 0],
        ]);

        $this->assertEqualsWithDelta(30.0, $blend['margin_pct'], 0.01);
    }

    /**
     * A row with no selling price has no margin at all (§S: things we buy and never
     * sell). It is left out rather than averaged in as a zero, because "we do not know"
     * and "it breaks even" are different answers.
     */
    public function test_a_row_with_no_selling_price_is_left_out_rather_than_counted_as_zero(): void
    {
        $blend = SkuMargin::combine([
            ['net_receivable' => 10.0, 'profit' => 3.0, 'cogs' => 7.0, 'margin_pct' => 30.0, 'units' => 10],
            ['net_receivable' => null, 'profit' => null, 'cogs' => 4.0, 'margin_pct' => null, 'units' => 10],
        ]);

        $this->assertEqualsWithDelta(30.0, $blend['margin_pct'], 0.01);
        $this->assertSame(1, $blend['channels_priced']);
        $this->assertSame(2, $blend['channels_total']);
        // The cost still blends over both: we know what both cost us.
        $this->assertEqualsWithDelta(5.5, $blend['cogs'], 0.001);
    }

    /** Nothing to weight means no answer, not 0%. */
    public function test_a_sku_with_no_priced_channel_has_no_margin_at_all(): void
    {
        $blend = SkuMargin::combine([
            ['net_receivable' => null, 'profit' => null, 'cogs' => 4.0, 'margin_pct' => null, 'units' => 5],
        ]);

        $this->assertNull($blend['margin_pct']);
    }

    /** "Amazon" is two channels — VC and DFS — so it is already a blend. */
    public function test_the_channel_selector_maps_to_the_right_channels(): void
    {
        $this->assertSame(['amazon_retail', 'amazon_dfs'], SkuMargin::channelsFor(SkuMargin::AMAZON));
        $this->assertSame(['noon_retail'], SkuMargin::channelsFor(SkuMargin::NOON));
        $this->assertCount(3, SkuMargin::channelsFor(SkuMargin::BOTH));
        // Anything unrecognised falls back to the widest view rather than an empty one.
        $this->assertSame(SkuMargin::BOTH, SkuMargin::selector('nonsense'));
    }

    /** End to end: two real channel rows, blended off the engine's own cached columns. */
    public function test_a_real_two_channel_product_blends_across_its_channels(): void
    {
        $product = $this->product();
        $this->economics($product, 'amazon_retail');
        // Noon: a better invoice rate, so a different margin on the same product.
        $this->economics($product, 'noon_retail', [
            'invoice_pct_of_rsp' => 0.98, 'net_pct_of_invoice' => 0.78,
        ]);

        $rows = SkuMargin::blendsForProducts([$product->id], SkuMargin::BOTH);
        $row = $rows[$product->id];

        $this->assertCount(2, $row['channels']);

        $amazon = collect($row['channels'])->firstWhere('label', 'Amazon Retail');
        $noon = collect($row['channels'])->firstWhere('label', 'Noon Retail');

        $this->assertGreaterThan($amazon['margin_pct'], $noon['margin_pct'],
            "Noon's better invoice rate must show as a better margin on the same product");

        // Nothing has shipped, so the blend is per unit and sits between the two.
        $this->assertSame(SkuMargin::BASIS_PER_UNIT, $row['blend']['weight_basis']);
        $this->assertGreaterThan($amazon['margin_pct'], $row['blend']['margin_pct']);
        $this->assertLessThan($noon['margin_pct'], $row['blend']['margin_pct']);
        $this->assertTrue($row['profitable']);
    }

    // =====================================================================
    // 3. THE SCREENS
    // =====================================================================

    public function test_the_profitability_screen_shows_the_po_level_p_and_l(): void
    {
        $po = $this->costablePo();

        $this->actingAs($this->unlockedAdmin())
            ->get(route('money.index'))
            ->assertOk()
            ->assertSee('Profitability')
            ->assertSee($po->po_number)
            ->assertSee('4.66%');
    }

    public function test_a_single_po_gets_a_full_statement(): void
    {
        $po = $this->costablePo();

        $this->actingAs($this->unlockedAdmin())
            ->get(route('money.po', $po->po_number))
            ->assertOk()
            ->assertSee('Invoiced to the marketplace')
            ->assertSee('Net receivable')
            ->assertSee('Net profit')
            // The zero cost lines are on screen, labelled, not hidden.
            ->assertSee('Less packaging')
            ->assertSee(ProfitAndLoss::UNTIL_DATA_ADDED);
    }

    public function test_the_sku_view_offers_the_amazon_noon_both_selector(): void
    {
        $product = $this->product();
        $this->economics($product, 'amazon_retail');
        $this->economics($product, 'noon_retail');

        $this->actingAs($this->unlockedAdmin())
            ->get(route('money.index', ['view' => 'sku']))
            ->assertOk()
            ->assertSee('Amazon')
            ->assertSee('Noon')
            ->assertSee('Both')
            ->assertSee($product->company_product_code)
            // The rule is stated on the screen, not just honoured in the code.
            ->assertSee('revenue-weighted', false);
    }

    public function test_the_sku_view_narrows_to_one_channel(): void
    {
        $amazonOnly = $this->product(['name' => 'Amazon only product']);
        $this->economics($amazonOnly, 'amazon_retail');

        $noonOnly = $this->product(['name' => 'Noon only product']);
        $this->economics($noonOnly, 'noon_retail');

        $this->actingAs($this->unlockedAdmin())
            ->get(route('money.index', ['view' => 'sku', 'channel_view' => SkuMargin::NOON]))
            ->assertOk()
            ->assertSee($noonOnly->company_product_code)
            ->assertDontSee($amazonOnly->company_product_code);
    }

    /**
     * Every statement line carries the same keys.
     *
     * A view reaching for a key that only some lines happen to have is a warning waiting
     * for the one PO that takes the other branch — and `lines()` has two branches, so the
     * PO that triggers it is the one with an uncostable line, which is exactly the PO
     * someone is looking at when they most need the screen to work.
     */
    public function test_every_statement_line_has_the_same_shape(): void
    {
        foreach ([$this->costablePo(), $this->partlyCostablePo()] as $po) {
            foreach (ProfitAndLoss::forPurchaseOrder($po)['lines'] as $line) {
                foreach (['label', 'note', 'amount', 'kind', 'pending'] as $key) {
                    $this->assertArrayHasKey($key, $line, "'{$line['label']}' is missing '{$key}'");
                }
            }
        }
    }

    /**
     * The SKU table stops at a screenful, and SAYS it stopped.
     *
     * Everything matching is still costed — that is the only way to know which the worst
     * margins are — so the KPIs describe all of them while the table shows the top of the
     * list. A cap nobody is told about would read as "this is all of them", which is the
     * one thing a screen about profitability must never imply.
     */
    public function test_the_sku_table_states_its_cap_rather_than_truncating_silently(): void
    {
        for ($i = 0; $i < SkuMargin::TABLE_LIMIT + 3; $i++) {
            $this->economics($this->product(), 'amazon_retail');
        }

        $response = $this->actingAs($this->unlockedAdmin())
            ->get(route('money.index', ['view' => 'sku']))
            ->assertOk();

        $response->assertSee('worst-margin');
        $response->assertSee('CSV export');
        // The headline counts every matching SKU, not only the ones drawn.
        $response->assertSee((string) (SkuMargin::TABLE_LIMIT + 3));
    }

    /**
     * A product M6 flagged for review carries the flag onto the margin screen.
     *
     * `BD62972744` in the real catalog is one code covering two products, so its margin is
     * arithmetic over a fiction. The row still appears — the SKU is real and somebody is
     * looking for it — with a warning rather than a confident number nobody should act on.
     */
    public function test_a_flagged_product_says_so_on_the_margin_screen(): void
    {
        $product = $this->product(['company_product_code' => 'BD62972744']);
        $this->economics($product, 'amazon_retail');

        \App\Models\MasterAnomaly::create([
            'product_id' => $product->id,
            'company_product_code' => $product->company_product_code,
            'kind' => \App\Models\MasterAnomaly::KIND_CODE_COVERS_TWO_PRODUCTS,
            'severity' => \App\Models\MasterAnomaly::SEVERITY_REVIEW,
            'message' => 'One code covering two products.',
        ]);

        $this->actingAs($this->unlockedAdmin())
            ->get(route('money.index', ['view' => 'sku']))
            ->assertOk()
            ->assertSee('BD62972744')
            ->assertSee('flagged — check before trusting', false);
    }

    public function test_both_views_export_to_csv(): void
    {
        $this->costablePo();
        $admin = $this->unlockedAdmin();

        $this->actingAs($admin)
            ->get(route('money.index', ['export' => 'csv']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($admin)
            ->get(route('money.index', ['view' => 'sku', 'export' => 'csv']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // =====================================================================
    // 4. THE GATE — order value open, margin closed
    // =====================================================================

    /**
     * THE SPLIT §O ASKS FOR, on the shared PO screen.
     *
     * Order value is how big the order is and is open to everyone. Margin is what we make
     * on it and is not. Getting this backwards in either direction is a real failure:
     * hiding order value makes the operational screens unusable, and showing margin
     * without the PIN defeats the whole gate.
     */
    public function test_po_detail_shows_order_value_without_the_pin_but_never_margin(): void
    {
        $po = $this->costablePo();

        $response = $this->actingAs($this->admin())
            ->get(route('po-lookup.show', $po->po_number))
            ->assertOk();

        // Order value: 100 x 30. Visible with no PIN at all.
        $response->assertSee('Order value');
        $response->assertSee('AED 3,000.00');

        // Margin, profit and our own cost: not a trace.
        $response->assertDontSee('Net P&L', false);
        $response->assertDontSee('Cost / unit');
        $response->assertDontSee('2,230.84');
        $response->assertDontSee('109.16');
    }

    public function test_po_detail_grows_the_money_columns_once_the_pin_is_in(): void
    {
        $po = $this->costablePo();

        $this->actingAs($this->unlockedAdmin())
            ->get(route('po-lookup.show', $po->po_number))
            ->assertOk()
            // Still there - unlocking adds, it never takes away.
            ->assertSee('Order value')
            ->assertSee('AED 3,000.00')
            // And now the money.
            ->assertSee('Cost / unit')
            ->assertSee('AED 2,230.84')
            ->assertSee('4.66%');
    }

    public function test_products_shows_sell_in_without_the_pin_but_never_margin(): void
    {
        $po = $this->costablePo();

        $response = $this->actingAs($this->admin())
            ->get(route('products.index'))
            ->assertOk();

        $response->assertSee('Sell-in');
        $response->assertSee('AED 3,000.00');
        $response->assertDontSee('Profit / unit');
        $response->assertDontSee('losing money');
    }

    public function test_products_grows_the_margin_columns_once_the_pin_is_in(): void
    {
        $this->costablePo();

        $this->actingAs($this->unlockedAdmin())
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('Sell-in')
            ->assertSee('Profit / unit')
            ->assertSee('Margin');
    }

    /**
     * A permission the PIN cannot conjure. Warehouse holds no money permission at all
     * (§O), so entering the PIN changes nothing for them — while order value, which is
     * NOT one of §O's money lenses, stays visible as it always was.
     */
    public function test_a_role_without_the_permission_sees_no_margin_even_with_the_pin(): void
    {
        $po = $this->costablePo();
        $warehouse = tap(User::factory()->create())->assignRole('Warehouse');

        $this->actingAs($warehouse)->post('/money-pin', ['pin' => '4321']);

        $this->actingAs($warehouse)
            ->get(route('po-lookup.show', $po->po_number))
            ->assertOk()
            ->assertSee('AED 3,000.00')       // order value: still theirs
            ->assertDontSee('Cost / unit')    // margin: never
            ->assertDontSee('AED 2,230.84');

        // And the Profitability section itself stays shut to them.
        $this->actingAs($warehouse)->get(route('money.index'))->assertForbidden();
    }

    /** The unlock nudge is offered only to someone the PIN would actually help. */
    public function test_the_unlock_prompt_is_shown_only_to_someone_who_could_use_it(): void
    {
        $po = $this->costablePo();

        $this->actingAs($this->admin())
            ->get(route('po-lookup.show', $po->po_number))
            ->assertOk()
            ->assertSee('enter the PIN to show');

        // Warehouse is never told about a door it has no key to.
        $warehouse = tap(User::factory()->create())->assignRole('Warehouse');

        $this->actingAs($warehouse)
            ->get(route('po-lookup.show', $po->po_number))
            ->assertOk()
            ->assertDontSee('enter the PIN to show');
    }

    // =====================================================================
    // 5. UNLOCK FOR THE SESSION
    // =====================================================================

    /** Entered once, it stays in across screens — no re-prompting per page. */
    public function test_the_pin_stays_unlocked_across_screens(): void
    {
        $po = $this->costablePo();
        $admin = $this->unlockedAdmin();

        $this->actingAs($admin)->get(route('overview.index'))->assertOk();
        $this->actingAs($admin)->get(route('fulfilment.index'))->assertOk();

        $this->actingAs($admin)
            ->get(route('po-lookup.show', $po->po_number))
            ->assertOk()
            ->assertSee('Cost / unit');
    }

    /**
     * The window is on IDLE time, and it slides on any activity — not only on the money
     * screens. Someone who unlocks, then spends the timeout working elsewhere in the app,
     * must not come back to find the columns silently gone.
     */
    public function test_working_elsewhere_in_the_app_keeps_the_unlock_alive(): void
    {
        config(['operon.money_pin_timeout' => 15]);

        $po = $this->costablePo();
        $admin = $this->unlockedAdmin();

        // Ten minutes pass, and a page is opened - somewhere with no money on it at all.
        $this->travel(10)->minutes();
        $this->actingAs($admin)->get(route('fulfilment.index'))->assertOk();

        // Ten more. Without the sliding window this would be 20 minutes of a 15-minute
        // unlock and would have lapsed.
        $this->travel(10)->minutes();

        $this->actingAs($admin)
            ->get(route('po-lookup.show', $po->po_number))
            ->assertOk()
            ->assertSee('Cost / unit');
    }

    /** Genuinely idle, though, and it locks. */
    public function test_an_idle_session_locks_itself(): void
    {
        config(['operon.money_pin_timeout' => 15]);

        $po = $this->costablePo();
        $admin = $this->unlockedAdmin();

        $this->travel(16)->minutes();

        $this->actingAs($admin)
            ->get(route('po-lookup.show', $po->po_number))
            ->assertOk()
            ->assertDontSee('Cost / unit')
            ->assertSee('enter the PIN to show');

        // And the Profitability section asks for it again.
        $this->actingAs($admin)->get(route('money.index'))->assertRedirect(route('money-pin.prompt'));
    }

    /** A lapsed session is not revived by the very request that noticed it had lapsed. */
    public function test_touching_a_lapsed_session_does_not_revive_it(): void
    {
        config(['operon.money_pin_timeout' => 15]);

        $admin = $this->unlockedAdmin();

        $this->travel(16)->minutes();

        // A request goes through TouchMoneyPinSession...
        $this->actingAs($admin)->get(route('overview.index'))->assertOk();

        // ...and the session is still locked.
        $this->actingAs($admin)->get(route('money.index'))->assertRedirect(route('money-pin.prompt'));
    }

    /** Locking by hand hides the figures at once, without logging out. */
    public function test_locking_by_hand_hides_the_money_immediately(): void
    {
        $po = $this->costablePo();
        $admin = $this->unlockedAdmin();

        $this->actingAs($admin)->post(route('money-pin.lock'))->assertRedirect();

        $this->actingAs($admin)
            ->get(route('po-lookup.show', $po->po_number))
            ->assertOk()
            ->assertDontSee('Cost / unit');
    }

    /** Logging out ends it, because the unlock lives in the session and nowhere else. */
    public function test_logging_out_ends_the_unlock(): void
    {
        $admin = $this->unlockedAdmin();

        $this->actingAs($admin)->post('/logout');

        $this->actingAs($admin)->get(route('money.index'))->assertRedirect(route('money-pin.prompt'));
    }

    /** The gate is one place, and it answers both halves of the question. */
    public function test_the_gate_needs_the_permission_and_the_pin_together(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);
        $this->assertTrue(MoneyGate::hasMarginPermission());
        $this->assertFalse(MoneyGate::unlocked());
        $this->assertFalse(MoneyGate::canSeeMargin());
        $this->assertTrue(MoneyGate::needsUnlock());

        MoneyGate::unlock();

        $this->assertTrue(MoneyGate::canSeeMargin());
        $this->assertFalse(MoneyGate::needsUnlock());
        $this->assertLessThanOrEqual(MoneyGate::timeoutMinutes(), MoneyGate::minutesRemaining());
    }
}
