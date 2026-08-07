<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Enums\Stage;
use App\Models\Delivery;
use App\Models\InventorySnapshot;
use App\Models\Product;
use App\Models\SelloutRow;
use App\Models\ShipmentLine;
use App\Services\Analytics\RunRate;
use App\Services\Analytics\SellThroughEngine;
use App\Services\Reporting\FilterSet;
use App\Services\Reporting\UnlinkedIdentifiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * M9 — velocity, days of cover, sell-through and the watchlists (§P, §R, §D).
 *
 * Days of cover is the number somebody reorders against, and it is three deep: stock, a
 * run rate, and the window that rate was measured over. Every test here is about a way
 * that chain can produce a confident wrong answer.
 */
class SellThroughAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function engine(array $channels = []): SellThroughEngine
    {
        return new SellThroughEngine(new FilterSet(channels: $channels));
    }

    private function sellOut(array $attributes = []): SelloutRow
    {
        return SelloutRow::create(array_merge([
            'marketplace' => Marketplace::Amazon,
            'channel' => Channel::AmazonRetail,
            'grain' => SelloutRow::GRAIN_PERIOD,
            'sku_id' => 'B08TEST0001',
            'sku_id_type' => 'asin',
            'title' => 'Test product',
            'period_start' => '2026-06-01',
            'period_end' => '2026-08-05',
            'shipped_units' => 660,
            'revenue' => 10000,
            'revenue_basis' => SelloutRow::BASIS_SHIPPED_COGS,
            'currency' => 'AED',
        ], $attributes));
    }

    private function stock(array $attributes = []): InventorySnapshot
    {
        return InventorySnapshot::create(array_merge([
            'marketplace' => Marketplace::Amazon,
            'channel' => Channel::AmazonRetail,
            'sku_id' => 'B08TEST0001',
            'sku_id_type' => 'asin',
            'title' => 'Test product',
            'snapshot_date' => '2026-08-05',
            'soh_units' => 1000,
            'currency' => 'AED',
        ], $attributes));
    }

    private function rowFor(string $skuId, ?Channel $channel = null): ?array
    {
        return $this->engine()->skuRows()
            ->first(fn (array $r) => $r['sku_id'] === $skuId
                && ($channel === null || $r['channel'] === $channel));
    }

    // --- Velocity: three channels, three qualities of answer ----------------

    /**
     * Amazon's sell-out report is ONE aggregated row per ASIN. There is no daily detail,
     * so the only honest rate is units ÷ window days — a PERIOD AVERAGE, and it says so.
     */
    public function test_amazon_velocity_is_a_period_average_and_is_labelled_as_one(): void
    {
        $this->sellOut(['shipped_units' => 660]);   // 66-day window
        $this->stock(['soh_units' => 1000]);

        $row = $this->rowFor('B08TEST0001');

        $this->assertSame(10.0, $row['run_rate'], '660 units ÷ 66 days');
        $this->assertTrue($row['run_rate_is_period_average']);
        $this->assertFalse($row['run_rate_is_stated']);
        $this->assertStringContainsString('period average', $row['run_rate_basis']);
        $this->assertSame(100.0, $row['cover_days'], '1,000 units ÷ 10 a day');
    }

    /** Noon publishes its own L7 rate; nothing we derive may override it. */
    public function test_noons_own_run_rate_wins_over_anything_derived(): void
    {
        $this->sellOut([
            'marketplace' => Marketplace::Noon,
            'channel' => Channel::NoonRetail,
            'grain' => SelloutRow::GRAIN_DAY,
            'sku_id' => 'Z00A34B562298A159D103Z-1',
            'period_start' => '2026-08-05',
            'period_end' => '2026-08-05',
            'shipped_units' => 2,
        ]);

        $this->stock([
            'marketplace' => Marketplace::Noon,
            'channel' => Channel::NoonRetail,
            'sku_id' => 'Z00A34B562298A159D103Z-1',
            'soh_units' => 2875,
            'daily_run_rate' => 32.428571,
        ]);

        $row = $this->rowFor('Z00A34B562298A159D103Z-1');

        $this->assertTrue($row['run_rate_is_stated']);
        $this->assertEqualsWithDelta(32.4286, $row['run_rate'], 0.001);
        $this->assertStringContainsString("Noon's own", $row['run_rate_basis']);
        // 2 units in a day would have given a rate of 2. The channel's figure won.
        $this->assertEqualsWithDelta(88.7, $row['cover_days'], 0.1);
    }

    /**
     * DFS has dated orders, so a real trailing average is derivable — and the window is
     * anchored on the DATA's last day, not on today. Counting back from today would score
     * zeroes against every SKU for the gap between the export and the upload.
     */
    public function test_dfs_velocity_is_a_trailing_average_anchored_on_the_data(): void
    {
        // Sales on four consecutive days ending well before "today".
        foreach (['2026-07-29', '2026-07-30', '2026-07-31', '2026-08-01'] as $date) {
            $this->sellOut([
                'channel' => Channel::AmazonDfs,
                'grain' => SelloutRow::GRAIN_DAY,
                'sku_id' => 'B08DFS00001',
                'period_start' => $date,
                'period_end' => $date,
                'shipped_units' => 7,
                'revenue_basis' => SelloutRow::BASIS_INVOICE_AMOUNT,
            ]);
        }

        $this->stock(['channel' => Channel::AmazonDfs, 'sku_id' => 'B08DFS00001', 'soh_units' => 140]);

        $row = $this->rowFor('B08DFS00001');

        // 28 units across the last 7 days OF THE DATA = 4/day. Anchored on today it would
        // have been 0, because the data stops days earlier.
        $this->assertSame(4.0, $row['run_rate']);
        $this->assertStringContainsString('last 7 days', $row['run_rate_basis']);
        $this->assertSame(35.0, $row['cover_days']);
    }

    /** A week of zeroes on a slow SKU is noise, not a stop: fall back to 30 days. */
    public function test_a_quiet_week_falls_back_to_the_thirty_day_rate(): void
    {
        // One sale 20 days before the channel's most recent day.
        foreach ([['2026-07-16', 30], ['2026-08-05', 0]] as [$date, $units]) {
            $this->sellOut([
                'channel' => Channel::AmazonDfs,
                'grain' => SelloutRow::GRAIN_DAY,
                'sku_id' => 'B08DFS00002',
                'period_start' => $date,
                'period_end' => $date,
                'shipped_units' => $units,
            ]);
        }

        $this->stock(['channel' => Channel::AmazonDfs, 'sku_id' => 'B08DFS00002', 'soh_units' => 100]);

        $row = $this->rowFor('B08DFS00002');

        $this->assertSame(1.0, $row['run_rate'], '30 units over 30 days, not zero over 7');
        $this->assertStringContainsString('last 30 days', $row['run_rate_basis']);
    }

    // --- The rules cover refuses to break -----------------------------------

    /**
     * A SKU selling nothing does not have "infinite" cover — it has UNDEFINED cover and a
     * dead-stock problem. A sentinel would sort straight to the top of every table.
     */
    public function test_a_zero_run_rate_gives_no_cover_rather_than_a_huge_number(): void
    {
        $rate = RunRate::derived(0, 66, 'nothing sold');

        $this->assertTrue($rate->isDead());
        $this->assertNull($rate->coverDays(5000), 'cover is undefined, not enormous');
        $this->assertNull(RunRate::unknown()->coverDays(100));
    }

    /**
     * THE ROW THAT IS EASIEST TO LOSE: stock on hand for a SKU that is not in the
     * sell-out report at all. It has no run rate to be low, so no cover-based rule can
     * ever see it — and it is usually the worst overstock we have.
     */
    public function test_stock_with_no_sell_out_at_all_still_reaches_the_overstock_list(): void
    {
        // A different SKU establishes that the channel HAS a 66-day sell-out window.
        $this->sellOut(['sku_id' => 'B08TEST0001', 'shipped_units' => 660]);

        // This one holds stock and appears nowhere in the report.
        $this->stock(['sku_id' => 'B08DEAD0001', 'title' => 'Dead stock', 'soh_units' => 1400]);

        $row = $this->rowFor('B08DEAD0001');

        $this->assertNotNull($row, 'a stock row with no sell-out must still produce a row');
        $this->assertSame(0, $row['sell_out_units']);
        $this->assertSame(0.0, $row['run_rate']);
        $this->assertNull($row['cover_days']);
        $this->assertNotNull($row['overstock_reason']);
        $this->assertStringContainsString('nothing sold in 66 days', $row['overstock_reason']);
    }

    /** Amazon's own "aged 90+" needs no run rate to be true, and is its own route in. */
    public function test_amazon_aged_stock_reaches_the_overstock_list_on_its_own(): void
    {
        // Selling briskly, so cover is healthy and the cover rule cannot fire.
        $this->sellOut(['sku_id' => 'B08AGED0001', 'shipped_units' => 6600]);
        $this->stock(['sku_id' => 'B08AGED0001', 'soh_units' => 500, 'aged_90_units' => 120]);

        $row = $this->rowFor('B08AGED0001');

        $this->assertLessThan(config('operon.cover.overstock_days'), $row['cover_days']);
        $this->assertNotNull($row['overstock_reason'], 'aged stock is overstock however well it sells');
        $this->assertStringContainsString('aged 90+', $row['overstock_reason']);
    }

    public function test_thin_cover_on_a_moving_sku_is_a_stock_out_risk(): void
    {
        $this->sellOut(['sku_id' => 'B08HOT00001', 'shipped_units' => 6600]);   // 100/day
        $this->stock(['sku_id' => 'B08HOT00001', 'soh_units' => 500]);          // 5 days

        $row = $this->rowFor('B08HOT00001');

        $this->assertSame(5.0, $row['cover_days']);
        $this->assertNotNull($row['stockout_reason']);
        $this->assertNull($row['overstock_reason']);

        $lists = $this->engine()->watchlists();
        $this->assertTrue($lists['under_supplying']['all']->contains(fn ($r) => $r['sku_id'] === 'B08HOT00001'));
    }

    /** Out of stock and still selling is the sharpest case, and is named as such. */
    public function test_out_of_stock_while_still_selling_is_flagged(): void
    {
        $this->sellOut(['sku_id' => 'B08OUT00001', 'shipped_units' => 660]);
        $this->stock(['sku_id' => 'B08OUT00001', 'soh_units' => 0]);

        $row = $this->rowFor('B08OUT00001');

        $this->assertStringContainsString('out of stock', $row['stockout_reason']);
    }

    /** A handful of units is not an overstock problem worth anybody's attention. */
    public function test_trivial_quantities_are_not_flagged(): void
    {
        $this->sellOut(['sku_id' => 'B08TEST0001', 'shipped_units' => 660]);
        $this->stock(['sku_id' => 'B08TINY0001', 'soh_units' => 3]);

        $this->assertNull($this->rowFor('B08TINY0001')['overstock_reason']);
    }

    // --- Sell-through: the denominator has to cover the same days -----------

    /**
     * THE 598% BUG, PINNED.
     *
     * Amazon's sell-out covers 66 days; on the real files nine of the eleven deliveries we
     * hold are dated AFTER that window closes. Dividing one by the other gave 598%, which
     * would have sat on the Overview tile looking like a triumph.
     */
    public function test_sell_through_is_withheld_when_the_two_windows_do_not_line_up(): void
    {
        $this->sellOut(['shipped_units' => 84_434]);

        // One delivery, inside the window, on a single day of 66.
        $this->shipIn(Channel::AmazonRetail, 'B08TEST0001', 1_079, '2026-06-15');

        $channel = $this->engine()->byChannel()
            ->first(fn (array $c) => $c['channel'] === Channel::AmazonRetail);

        $this->assertNull($channel['sell_through_pct'], 'one day inside a 66-day window is not a denominator');
        $this->assertStringContainsString('Not comparable', $channel['sell_through_note']);
        // The units are still reported on both sides — only the ratio is withheld.
        $this->assertSame(84_434, $channel['sell_out_units']);
        $this->assertSame(1_079, $channel['sell_in_window_units']);
    }

    /**
     * The channel's OWN received count is aligned with the report window by construction,
     * so it is the denominator whenever we have it.
     */
    public function test_sell_through_uses_the_channels_own_received_units_when_present(): void
    {
        $this->sellOut(['shipped_units' => 84_434]);
        $this->stock(['soh_units' => 61_917, 'net_received_units' => 127_114]);

        $channel = $this->engine()->byChannel()
            ->first(fn (array $c) => $c['channel'] === Channel::AmazonRetail);

        $this->assertSame(66.4, $channel['sell_through_pct'], '84,434 ÷ 127,114');
        $this->assertSame(127_114, $channel['sell_through_denominator']);
        $this->assertStringContainsString('Net Received Units', $channel['sell_through_basis']);
        $this->assertSame(42_680, $channel['sitting_units']);
    }

    /** DFS has no sell-in step, so its ratio would be 100% by construction. */
    public function test_dfs_reports_no_sell_through_and_says_why(): void
    {
        $this->sellOut([
            'channel' => Channel::AmazonDfs,
            'grain' => SelloutRow::GRAIN_DAY,
            'sku_id' => 'B08DFS00001',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-01',
            'shipped_units' => 100,
        ]);

        $channel = $this->engine()->byChannel()
            ->first(fn (array $c) => $c['channel'] === Channel::AmazonDfs);

        $this->assertNull($channel['sell_through_pct']);
        $this->assertNull($channel['sell_in_units']);
        $this->assertStringContainsString('no sell-in step', $channel['sell_through_note']);
        $this->assertSame(100, $channel['sell_out_units'], 'the units themselves are real');
    }

    /** DFS stock carries its provisional label all the way to the channel rollup. */
    public function test_dfs_stock_stays_labelled_provisional_at_channel_level(): void
    {
        $this->stock([
            'channel' => Channel::AmazonDfs,
            'sku_id' => 'B08DFS00001',
            'soh_units' => 22_521,
            'is_provisional' => true,
            'provisional_note' => InventorySnapshot::DFS_PROVISIONAL_NOTE,
        ]);

        $channel = $this->engine()->byChannel()
            ->first(fn (array $c) => $c['channel'] === Channel::AmazonDfs);

        $this->assertTrue($channel['stock_is_provisional']);
        $this->assertSame(InventorySnapshot::DFS_PROVISIONAL_NOTE, $channel['stock_note']);
    }

    /** The same ASIN on two channels is two positions, never one merged row. */
    public function test_a_sku_selling_on_two_channels_keeps_them_apart(): void
    {
        $this->sellOut(['sku_id' => 'B08BOTH0001', 'shipped_units' => 660]);
        $this->stock(['sku_id' => 'B08BOTH0001', 'soh_units' => 1000]);

        $this->sellOut([
            'channel' => Channel::AmazonDfs,
            'grain' => SelloutRow::GRAIN_DAY,
            'sku_id' => 'B08BOTH0001',
            'period_start' => '2026-08-05',
            'period_end' => '2026-08-05',
            'shipped_units' => 5,
        ]);
        $this->stock(['channel' => Channel::AmazonDfs, 'sku_id' => 'B08BOTH0001', 'soh_units' => 50]);

        $rows = $this->engine()->skuRows()->filter(fn (array $r) => $r['sku_id'] === 'B08BOTH0001');

        $this->assertCount(2, $rows);
        $this->assertSame(1000, $this->rowFor('B08BOTH0001', Channel::AmazonRetail)['soh_units']);
        $this->assertSame(50, $this->rowFor('B08BOTH0001', Channel::AmazonDfs)['soh_units']);
    }

    /** The channel selector narrows sell-out and stock, not just PO lines. */
    public function test_the_channel_filter_reaches_sell_out_and_stock(): void
    {
        $this->sellOut(['sku_id' => 'B08AMZ00001']);
        $this->sellOut([
            'channel' => Channel::AmazonDfs, 'grain' => SelloutRow::GRAIN_DAY,
            'sku_id' => 'B08DFS00001', 'period_start' => '2026-08-05', 'period_end' => '2026-08-05',
        ]);

        $rows = $this->engine([Channel::AmazonDfs])->skuRows();

        $this->assertCount(1, $rows);
        $this->assertSame('B08DFS00001', $rows->first()['sku_id']);
    }

    // --- M8 refinement 1: the Noon delivery date is never invented ----------

    /**
     * M8 stood the upload day in for a missing Noon delivery date and marked it inferred.
     * An inferred date still drives turnaround and still reads like a fact, so M9 removed
     * the fallback entirely.
     */
    public function test_a_noon_delivery_has_no_fulfilment_date_until_someone_types_one(): void
    {
        $delivery = Delivery::create([
            'marketplace' => Marketplace::Noon,
            'channel' => Channel::NoonRetail,
            'delivery_key' => 'NOON:287285145169960',
            'internal_ref' => '287285145169960',
            'has_final' => true,
            'final_uploaded_at' => Carbon::parse('2026-08-07'),
            'planned_date' => '2026-07-22',   // Noon's own ESTIMATE
        ]);

        $this->assertNull($delivery->fulfilmentDate(), 'the upload day must not stand in');
        $this->assertFalse($delivery->fulfilmentDateIsInferred());
        $this->assertTrue($delivery->awaitingDeliveryDate());

        // The estimate is shown meanwhile — and always labelled as an estimate.
        $this->assertSame('2026-07-22', $delivery->shownDate()->toDateString());
        $this->assertSame(Delivery::ESTIMATED_LABEL, $delivery->shownDateNote());

        // And it appears on the list of deliveries waiting for a real date.
        $this->assertSame(1, Delivery::awaitingDate()->count());
    }

    public function test_typing_the_real_date_makes_turnaround_measurable_again(): void
    {
        $delivery = Delivery::create([
            'marketplace' => Marketplace::Noon,
            'channel' => Channel::NoonRetail,
            'delivery_key' => 'NOON:1',
            'has_final' => true,
            'final_uploaded_at' => Carbon::parse('2026-08-07'),
            'planned_date' => '2026-07-22',
            'delivered_on' => '2026-07-23',
            'delivery_date_is_manual' => true,
        ]);

        $this->assertSame('2026-07-23', $delivery->fulfilmentDate()->toDateString());
        $this->assertFalse($delivery->awaitingDeliveryDate());
        $this->assertSame('entered by hand', $delivery->shownDateNote());
        $this->assertSame(0, Delivery::awaitingDate()->count());
    }

    /** Amazon is untouched: its final packing list states a real shipment date. */
    public function test_amazon_deliveries_keep_their_inferred_date_fallback(): void
    {
        $delivery = Delivery::create([
            'marketplace' => Marketplace::Amazon,
            'channel' => Channel::AmazonRetail,
            'delivery_key' => '22161964743',
            'has_final' => true,
            'final_uploaded_at' => Carbon::parse('2026-08-07'),
        ]);

        $this->assertSame('2026-08-07', $delivery->fulfilmentDate()->toDateString());
        $this->assertTrue($delivery->fulfilmentDateIsInferred());
        $this->assertFalse($delivery->awaitingDeliveryDate());
    }

    // --- M8 refinement 2: identifiers the catalog does not hold -------------

    /**
     * An unmatched row is stored rather than dropped (§K), which makes it invisible — it
     * carries no brand, category or margin and falls out of every rollup silently. This
     * is what makes it visible again.
     */
    public function test_identifiers_not_in_the_catalog_are_listed_with_where_they_turned_up(): void
    {
        $this->sellOut(['sku_id' => 'B08UNKNOWN1', 'title' => 'Unknown product', 'shipped_units' => 40]);
        $this->stock(['sku_id' => 'B08UNKNOWN1', 'soh_units' => 12]);

        $entries = UnlinkedIdentifiers::all();
        $entry = $entries->firstWhere('sku_id', 'B08UNKNOWN1');

        $this->assertNotNull($entry);
        $this->assertSame('ASIN', $entry['kind']);
        $this->assertSame('Unknown product', $entry['title']);
        $this->assertArrayHasKey('sold to customers', $entry['seen_in']);
        $this->assertArrayHasKey('holding stock', $entry['seen_in']);

        // Traded means we lost something by leaving it out: ordered, delivered or sold.
        $this->assertTrue(UnlinkedIdentifiers::traded()->contains(fn ($e) => $e['sku_id'] === 'B08UNKNOWN1'));
    }

    /** A Noon NIN is recognised as a NIN, so the fix list says what to add. */
    public function test_a_noon_nin_is_named_and_recognised_on_the_fix_list(): void
    {
        $this->shipIn(Channel::NoonRetail, 'Z2711427219A2E6791868Z-1', 36, '2026-07-23',
            'Brandsfinity Facial Tissue Cube Box');

        $entry = UnlinkedIdentifiers::all()->firstWhere('sku_id', 'Z2711427219A2E6791868Z-1');

        $this->assertNotNull($entry, 'the one unlinked Noon line must be named, not merely counted');
        $this->assertSame('NIN', $entry['kind']);
        $this->assertSame(Marketplace::Noon, $entry['marketplace']);
        $this->assertSame(36, $entry['units']);
        $this->assertStringContainsString('Z2711427219A2E6791868Z-1', UnlinkedIdentifiers::describe($entry));
    }

    /** It is derived, not stored — so adding the SKU removes it with nothing to dismiss. */
    public function test_the_fix_list_clears_itself_when_the_row_links_up(): void
    {
        $row = $this->sellOut(['sku_id' => 'B08UNKNOWN1']);

        $this->assertSame(1, UnlinkedIdentifiers::count());

        $product = Product::create(['company_product_code' => 'BD00000001']);
        $row->update(['product_id' => $product->id, 'is_unmatched' => false]);

        $this->assertSame(0, UnlinkedIdentifiers::count());
    }

    /** Ship units into a channel on a given day, the way a final packing list would. */
    private function shipIn(Channel $channel, string $skuId, int $qty, string $date, ?string $title = null): void
    {
        $delivery = Delivery::create([
            'marketplace' => $channel->marketplace(),
            'channel' => $channel,
            'delivery_key' => 'D'.$date.$skuId,
            'has_final' => true,
            'delivered_on' => $date,
        ]);

        ShipmentLine::create([
            'delivery_id' => $delivery->id,
            'marketplace' => $channel->marketplace(),
            'channel' => $channel,
            'stage' => Stage::Final,
            'po_number' => 'PO'.$skuId,
            'sku_id' => $skuId,
            'title' => $title,
            'qty' => $qty,
            'is_unmatched' => true,
        ]);
    }
}
