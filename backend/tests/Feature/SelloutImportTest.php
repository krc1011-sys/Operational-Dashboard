<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Enums\SourceFileStatus;
use App\Enums\UploadType;
use App\Models\DfsOrder;
use App\Models\InventorySnapshot;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\SelloutRow;
use App\Models\SourceFile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeWorkbook;
use Tests\TestCase;

/**
 * M9 — sell-out and stock ingest, on all three channels (§P, §R).
 *
 * The tests here are chosen for the mistakes that would be SILENT. Every one of them
 * produces a number that looks entirely reasonable on a screen while being wrong:
 *
 *  - taking "Shipped Revenue" as ours instead of "Shipped COGS" (0.8% apart);
 *  - reading the banner's 05/08/2026 as 8 May instead of 5 August (trebles the run rate);
 *  - treating the DFS Excel serial 46204 as the number forty-six thousand;
 *  - failing to map a Noon barcode to its NIN, so stock and velocity never meet;
 *  - letting DFS stock lose its provisional label on the way to a screen.
 */
class SelloutImportTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        return tap(User::factory()->create())->assignRole('Admin');
    }

    private function upload(FakeWorkbook $book, string $name, string $extension = 'xlsx'): UploadedFile
    {
        $path = $this->tempFiles[] = $book->write($extension);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function uploadPath(string $path, string $name): UploadedFile
    {
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function import(UploadType $type, UploadedFile $file): SourceFile
    {
        $this->actingAs($this->admin())->post('/uploads', [
            'upload_type' => $type->value,
            'file' => $file,
        ]);

        return SourceFile::latest('id')->first();
    }

    /** A catalog product with an Amazon ASIN, so key resolution has something to find. */
    private function catalogProduct(string $code, Marketplace $marketplace, string $skuId): Product
    {
        $product = Product::create(['company_product_code' => $code, 'name' => 'Test product']);

        ProductIdentifier::create([
            'product_id' => $product->id,
            'marketplace' => $marketplace,
            'sku_id' => $skuId,
        ]);

        return $product;
    }

    // --- Amazon sell-out ----------------------------------------------------

    /**
     * THE COLUMN THAT IS A TRAP.
     *
     * "Shipped COGS" is what Amazon paid US — our revenue. "Shipped Revenue" is what the
     * customer paid Amazon, and is not money we ever see. On the real file they are 0.8%
     * apart, so taking the wrong one would never look wrong.
     */
    public function test_amazon_sell_out_revenue_is_shipped_cogs_not_shipped_revenue(): void
    {
        $file = $this->import(UploadType::AmazonSellout, $this->upload(
            FakeWorkbook::amazonSellout([['B08TEST0001', 'Test', 9310.50, 9800.00, 400, 4]]),
            'Sales_ASIN_Sourcing_Retail_x.xlsx'
        ));

        $this->assertSame(SourceFileStatus::Imported, $file->status);

        $row = SelloutRow::sole();

        $this->assertSame('9800.0000', $row->revenue, 'revenue must be Shipped COGS — what Amazon paid us');
        $this->assertSame('9310.5000', $row->shipped_revenue, 'the consumer price is kept, for context only');
        $this->assertSame(SelloutRow::BASIS_SHIPPED_COGS, $row->revenue_basis);
        $this->assertSame(400, $row->shipped_units);
        $this->assertSame(4, $row->customer_returns);

        // And the summary the audit log shows must say the same thing. Compared loosely
        // because a whole amount survives the JSON round-trip as an int, not a float.
        $this->assertEquals(9800.0, data_get($file->summary, 'sell_out_revenue'));
        $this->assertEquals(9310.5, data_get($file->summary, 'consumer_retail_revenue'));
    }

    /**
     * The banner's window, read as dd/mm/yyyy.
     *
     * `Carbon::parse('05/08/2026')` gives 8 MAY. The window would shrink from 66 days to
     * a negative span, and every run rate derived from it would be wrong — so the format
     * is stated, and this asserts the right end of the window.
     */
    public function test_the_reporting_window_comes_from_the_banner_and_is_day_first(): void
    {
        $file = $this->import(UploadType::AmazonSellout, $this->upload(
            FakeWorkbook::amazonSellout(null, '01/06/2026', '05/08/2026'),
            'Sales_ASIN_Sourcing_Retail_x.xlsx'
        ));

        $row = SelloutRow::first();

        $this->assertSame('2026-06-01', $row->period_start->toDateString());
        $this->assertSame('2026-08-05', $row->period_end->toDateString(), '05/08 is 5 August, not 8 May');
        $this->assertSame(66, data_get($file->summary, 'period_days'));
        $this->assertSame(SelloutRow::GRAIN_PERIOD, $row->grain);
    }

    /** No window in the banner means no run rate is derivable — so the file is refused. */
    public function test_a_sell_out_report_with_no_window_is_rejected_rather_than_guessed(): void
    {
        $book = (new FakeWorkbook)->sheet('Sheet0', [
            ['Program=[Retail]', 'Currency=[AED]'],
            ['ASIN', 'Product Title', 'Shipped COGS', 'Shipped Units'],
            ['B08TEST0001', 'Test', 100.0, 10],
        ]);

        $file = $this->import(UploadType::AmazonSellout, $this->upload($book, 'Sales_ASIN_x.xlsx'));

        $this->assertSame(SourceFileStatus::Failed, $file->status);
        $this->assertStringContainsString('reporting window', $file->rejection_reason);
        $this->assertSame(0, SelloutRow::count());
    }

    /** Re-uploading the same window replaces it: a dropped SKU must stop counting. */
    public function test_re_uploading_a_window_replaces_it_rather_than_doubling_it(): void
    {
        $this->import(UploadType::AmazonSellout, $this->upload(
            FakeWorkbook::amazonSellout([
                ['B08TEST0001', 'One', 100.0, 110.0, 10, 0],
                ['B08TEST0002', 'Two', 200.0, 220.0, 20, 0],
            ]),
            'Sales_ASIN_x.xlsx'
        ));

        $this->assertSame(2, SelloutRow::count());

        // The second export no longer carries B08TEST0002 at all.
        $this->import(UploadType::AmazonSellout, $this->upload(
            FakeWorkbook::amazonSellout([['B08TEST0001', 'One', 100.0, 110.0, 15, 0]]),
            'Sales_ASIN_x.xlsx'
        ));

        $this->assertSame(1, SelloutRow::count(), 'the dropped SKU must not linger with its old figures');
        $this->assertSame(15, SelloutRow::sole()->shipped_units);
    }

    /** Rows whose ASIN the catalog does not hold are STORED and flagged, never dropped. */
    public function test_an_unknown_asin_is_stored_and_flagged_rather_than_dropped(): void
    {
        $this->catalogProduct('BD00000001', Marketplace::Amazon, 'B08TEST0001');

        $file = $this->import(UploadType::AmazonSellout, $this->upload(
            FakeWorkbook::amazonSellout([
                ['B08TEST0001', 'Known', 100.0, 110.0, 10, 0],
                ['B08UNKNOWN1', 'Unknown', 200.0, 220.0, 20, 0],
            ]),
            'Sales_ASIN_x.xlsx'
        ));

        $this->assertSame(2, SelloutRow::count());
        $this->assertSame(1, $file->rows_unmatched);
        $this->assertTrue(SelloutRow::where('sku_id', 'B08UNKNOWN1')->sole()->is_unmatched);
        $this->assertNotNull(SelloutRow::where('sku_id', 'B08TEST0001')->sole()->product_id);
    }

    // --- Amazon inventory ---------------------------------------------------

    public function test_amazon_stock_keeps_aged_open_po_and_net_received(): void
    {
        $file = $this->import(UploadType::AmazonInventory, $this->upload(
            FakeWorkbook::amazonInventory([['B08TEST0001', 1200, 40, 500, 2000]]),
            'Inventory_ASIN_Sourcing_Retail_x.xlsx'
        ));

        $this->assertSame(SourceFileStatus::Imported, $file->status);

        $snapshot = InventorySnapshot::sole();

        $this->assertSame(1200, $snapshot->soh_units);
        $this->assertSame(40, $snapshot->aged_90_units, 'Amazon\'s own overstock signal');
        $this->assertSame(500, $snapshot->open_po_units);
        $this->assertSame(2000, $snapshot->net_received_units);
        $this->assertFalse($snapshot->is_provisional);

        // Dated on the banner's "Report Updated", not on today.
        $this->assertSame('2026-08-05', $snapshot->snapshot_date->toDateString());

        // Amazon writes these as fractions; they are stored once, as percentages.
        $this->assertSame('16.3600', $snapshot->receive_fill_pct);
    }

    /** Stock is a level: the same day uploaded twice is one answer, not two. */
    public function test_re_uploading_stock_for_the_same_day_replaces_it(): void
    {
        foreach ([1200, 900] as $units) {
            $this->import(UploadType::AmazonInventory, $this->upload(
                FakeWorkbook::amazonInventory([['B08TEST0001', $units, 0, 0, 100]]),
                'Inventory_ASIN_x.xlsx'
            ));
        }

        $this->assertSame(1, InventorySnapshot::count());
        $this->assertSame(900, InventorySnapshot::sole()->soh_units);
    }

    // --- DFS ----------------------------------------------------------------

    /**
     * DFS orders are stored twice on purpose: as transaction detail, and as a daily
     * per-ASIN projection so velocity works the same way it does on Noon.
     */
    public function test_dfs_orders_are_stored_and_projected_to_a_daily_sell_out_series(): void
    {
        $file = $this->import(UploadType::AmazonDfs, $this->upload(
            FakeWorkbook::dfsSales([
                ['ORDER0001', 'B08TEST0001', 46204, 2, 85.80],
                ['ORDER0002', 'B08TEST0001', 46204, 1, 42.90],
                ['ORDER0003', 'B08TEST0001', 46205, 3, 128.70],
            ]),
            'DFS Sales_July.xlsx'
        ));

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame(3, DfsOrder::count(), 'every order line is kept');

        // Two ORDERS on 1 Jul collapse into ONE daily sell-out row of 3 units.
        $daily = SelloutRow::where('channel', Channel::AmazonDfs->value)->orderBy('period_start')->get();

        $this->assertCount(2, $daily, 'one row per ASIN per day');
        $this->assertSame('2026-07-01', $daily[0]->period_start->toDateString(), '46204 is 1 Jul 2026');
        $this->assertSame(3, $daily[0]->shipped_units);
        $this->assertSame('128.7000', $daily[0]->revenue);
        $this->assertSame(SelloutRow::GRAIN_DAY, $daily[0]->grain);
        $this->assertSame(SelloutRow::BASIS_INVOICE_AMOUNT, $daily[0]->revenue_basis);

        $this->assertSame('2026-07-02', $daily[1]->period_start->toDateString());
        $this->assertSame(3, $daily[1]->shipped_units);
    }

    /** Monthly extracts overlap; re-uploading must not double an order or a day. */
    public function test_re_uploading_an_overlapping_dfs_window_does_not_double_count(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->import(UploadType::AmazonDfs, $this->upload(
                FakeWorkbook::dfsSales([['ORDER0001', 'B08TEST0001', 46204, 2, 85.80]]),
                'DFS Sales_July.xlsx'
            ));
        }

        $this->assertSame(1, DfsOrder::count());
        $this->assertSame(1, SelloutRow::where('channel', Channel::AmazonDfs->value)->count());
        $this->assertSame(2, SelloutRow::where('channel', Channel::AmazonDfs->value)->sole()->shipped_units);
    }

    /**
     * DFS stock is PROVISIONAL, and the flag rides on the row rather than being inferred
     * from the channel — so no screen can show the number without the caveat.
     */
    public function test_dfs_stock_is_stored_provisional_and_unwraps_its_excel_text_formulas(): void
    {
        $file = $this->import(UploadType::AmazonDfsInventory, $this->uploadPath(
            FakeWorkbook::dfsInventoryCsv([['B08TEST0001', '726208185355', 300]]),
            'amazon_df_inv_bulk_2026-08-07.csv'
        ));

        $this->assertSame(SourceFileStatus::Imported, $file->status);

        $snapshot = InventorySnapshot::sole();

        $this->assertSame(300, $snapshot->soh_units);
        $this->assertTrue($snapshot->is_provisional);
        $this->assertSame(InventorySnapshot::DFS_PROVISIONAL_NOTE, $snapshot->provisional_note);
        $this->assertSame('FDOX', $snapshot->warehouse_code);

        // ="0726208185355" must reduce to plain digits, not survive as a formula string.
        $this->assertSame('726208185355', $snapshot->barcode_key);

        $this->assertTrue(collect($file->warnings)->contains(
            fn (string $w) => str_contains($w, InventorySnapshot::DFS_PROVISIONAL_NOTE)
        ), 'the upload itself must say the stock is provisional');
    }

    // --- Noon ---------------------------------------------------------------

    /**
     * THE JOIN THAT MAKES NOON COVER POSSIBLE.
     *
     * Sell-out is keyed by barcode, stock by NIN. The workbook's own "Barcodes" tab is the
     * only bridge; without it Noon would have stock with no velocity and velocity with no
     * stock, and every SKU would look either infinitely covered or completely dead.
     */
    public function test_noon_sell_out_is_mapped_from_barcode_onto_the_nin(): void
    {
        $file = $this->import(UploadType::NoonSellout, $this->upload(
            FakeWorkbook::noonSellout(),
            'Noon Sell out_BD Sell Out & SOH.xlsx'
        ));

        $this->assertSame(SourceFileStatus::Imported, $file->status);

        $mapped = SelloutRow::where('sku_id', 'Z00A34B562298A159D103Z-1')->get();

        $this->assertCount(2, $mapped, 'two days of sell-out, keyed on the NIN the map gave us');
        $this->assertSame('nin', $mapped->first()->sku_id_type);
        $this->assertSame(SelloutRow::GRAIN_DAY, $mapped->first()->grain);
        $this->assertSame(SelloutRow::BASIS_GMV, $mapped->first()->revenue_basis);

        // 46239 is 5 Aug 2026 — an Excel serial, not the number forty-six thousand.
        $this->assertSame('2026-08-05', $mapped->sortByDesc('period_start')->first()->period_start->toDateString());
    }

    /** A barcode the workbook's own map does not cover is kept, keyed on the barcode. */
    public function test_a_barcode_missing_from_the_map_is_kept_rather_than_dropped(): void
    {
        $file = $this->import(UploadType::NoonSellout, $this->upload(
            FakeWorkbook::noonSellout(),
            'Noon Sell out.xlsx'
        ));

        $orphan = SelloutRow::where('sku_id', '9990000000001')->sole();

        $this->assertSame('barcode', $orphan->sku_id_type);
        $this->assertSame(1, $orphan->shipped_units);
        $this->assertTrue(collect($file->warnings)->contains(
            fn (string $w) => str_contains($w, '9990000000001')
        ), 'the unmapped barcode must be named, not merely counted');
    }

    /** Noon publishes its own daily run rate; it is stored as given. */
    public function test_noon_stock_keeps_noons_own_l7_run_rate(): void
    {
        $file = $this->import(UploadType::NoonSellout, $this->upload(
            FakeWorkbook::noonSellout(),
            'Noon Sell out.xlsx'
        ));

        $stock = InventorySnapshot::where('sku_id', 'Z00A34B562298A159D103Z-1')->sole();

        $this->assertSame(2875, $stock->soh_units);
        $this->assertSame('32.4286', $stock->daily_run_rate);
        $this->assertSame('nin', $stock->sku_id_type);
        $this->assertFalse($stock->is_provisional);

        // The SOH tab has no date; it is dated on the workbook's freshest sell-out day.
        $this->assertSame('2026-08-05', $stock->snapshot_date->toDateString());
        $this->assertSame('2026-08-05', data_get($file->summary, 'stock_as_at'));
    }

    /** One upload, both halves — the file is called "Sell Out & SOH" for a reason. */
    public function test_one_noon_upload_ingests_both_sell_out_and_stock(): void
    {
        $file = $this->import(UploadType::NoonSellout, $this->upload(
            FakeWorkbook::noonSellout(),
            'Noon Sell out.xlsx'
        ));

        $this->assertGreaterThan(0, SelloutRow::where('channel', Channel::NoonRetail->value)->count());
        $this->assertSame(2, InventorySnapshot::where('channel', Channel::NoonRetail->value)->count());
        $this->assertSame(2, data_get($file->summary, 'barcode_map_entries'));
        $this->assertSame(2, data_get($file->summary, 'stock_rows'));
    }

    // --- The dropdown -------------------------------------------------------

    /** Uploading stock needs its own right, which Warehouse does not hold at launch. */
    public function test_the_new_m9_types_are_behind_their_own_permissions(): void
    {
        foreach ([
            [UploadType::AmazonInventory, 'upload-inventory'],
            [UploadType::AmazonDfsInventory, 'upload-inventory'],
            [UploadType::NoonSellout, 'upload-noon-sellout'],
            [UploadType::AmazonSellout, 'upload-sellout'],
            [UploadType::AmazonDfs, 'upload-dfs'],
        ] as [$type, $permission]) {
            $this->assertSame($permission, $type->permission());
        }

        // At launch every upload is Admin-only (§O), so nobody else may choose them.
        $this->actingAs(tap(User::factory()->create())->assignRole('Warehouse'))
            ->post('/uploads', [
                'upload_type' => UploadType::AmazonInventory->value,
                'file' => $this->upload(FakeWorkbook::amazonInventory(), 'Inventory_x.xlsx'),
            ])
            ->assertSessionHasErrors('upload_type');
    }
}
