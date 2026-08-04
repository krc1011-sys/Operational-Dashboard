<?php

namespace Tests\Feature;

use App\Enums\CancellationResolution;
use App\Enums\Marketplace;
use App\Enums\SourceFileStatus;
use App\Enums\Stage;
use App\Enums\UploadType;
use App\Models\Cancellation;
use App\Models\Delivery;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\ShipmentLine;
use App\Models\SourceFile;
use App\Models\User;
use App\Services\Import\AmazonPoImporter;
use App\Services\Upload\UploadService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeWorkbook;
use Tests\TestCase;

/**
 * The M3 Amazon importers.
 *
 * The real sample files are business data and cannot live in the repo, so these tests
 * use stand-ins with the same shape. The real files are checked separately by
 * `php artisan operon:verify-samples`, whose expected figures are the ones the
 * blueprint validated by hand.
 *
 * What these tests exist to catch is the arithmetic that the real samples happen not to
 * exercise — above all cancellation netting, since every cancellation in the sample set
 * names a PO that was not supplied.
 */
class AmazonImportTest extends TestCase
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

    /** Push a file through the real upload pipeline, exactly as the web form does. */
    private function ingest(
        FakeWorkbook $book,
        UploadType $type,
        string $filename,
        string $extension = 'xlsx',
        array $context = []
    ): SourceFile {
        $path = $this->tempFiles[] = $book->write($extension);

        return app(UploadService::class)->handle(
            new UploadedFile($path, $filename, null, null, true),
            $type,
            $this->admin(),
            $context
        );
    }

    // --- The PO importer ----------------------------------------------------

    public function test_a_bulk_po_export_creates_purchase_orders_and_lines(): void
    {
        $file = $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame(2, $file->rows_imported);

        $po = PurchaseOrder::sole();
        $this->assertSame('774FV9FB', $po->po_number);
        $this->assertSame('1F6RD', $po->vendor_code);
        $this->assertSame('DXB3', $po->ship_to_fc);
        $this->assertSame('2026-08-03', $po->order_date->toDateString());

        $line = PoLine::where('sku_id', 'B08TEST0001')->sole();
        $this->assertSame(200, $line->qty_requested);
        $this->assertSame(180, $line->qty_accepted);
        // Read as text, so the leading zero survives (§B).
        $this->assertSame('0634562947130', $line->barcode);
        $this->assertSame(180, $line->qty_net_accepted);
        $this->assertSame(PoLine::STATE_NOT_BOOKED, $line->line_state);
    }

    /** §C: re-uploading upserts. Latest snapshot wins, no duplicates. */
    public function test_re_uploading_a_po_updates_rather_than_duplicates(): void
    {
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');

        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(2, PoLine::count());
    }

    public function test_a_single_po_file_takes_its_po_number_from_the_filename(): void
    {
        $file = $this->ingest(FakeWorkbook::singlePo(), UploadType::AmazonPoSingle, 'PurchaseOrder_6QT4G44D.xlsx');

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame('6QT4G44D', PurchaseOrder::sole()->po_number);
    }

    public function test_a_single_po_file_accepts_a_typed_po_number_when_the_filename_has_none(): void
    {
        $file = $this->ingest(
            FakeWorkbook::singlePo(),
            UploadType::AmazonPoSingle,
            'PurchaseOrder.xlsx',
            context: ['po_number' => '6qt4g44d']
        );

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame('6QT4G44D', PurchaseOrder::sole()->po_number);
    }

    public function test_a_single_po_file_with_no_po_number_anywhere_fails_with_a_clear_message(): void
    {
        $file = $this->ingest(FakeWorkbook::singlePo(), UploadType::AmazonPoSingle, 'PurchaseOrder.xlsx');

        $this->assertSame(SourceFileStatus::Failed, $file->status);
        $this->assertStringContainsString('type the PO number', $file->rejection_reason);
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_po_numbers_are_recognised_in_filenames_but_words_are_not(): void
    {
        $this->assertSame('6QT4G44D', AmazonPoImporter::poNumberFromFilename('PurchaseOrder_6QT4G44D.xlsx'));
        $this->assertSame('774FV9FB', AmazonPoImporter::poNumberFromFilename('PO 774FV9FB final.xlsx'));
        $this->assertNull(AmazonPoImporter::poNumberFromFilename('PurchaseOrder.xlsx'));
        $this->assertNull(AmazonPoImporter::poNumberFromFilename('Purchase Order.xlsx'));
    }

    // --- The packing-list importer ------------------------------------------

    public function test_an_interim_packing_list_skips_carton_totals_and_sums_split_cartons(): void
    {
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');

        $file = $this->ingest(
            FakeWorkbook::packingList(),
            UploadType::AmazonInterimPacking,
            'PACKING LIST_Aug-01-22161389743 - Interim.xlsx'
        );

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame(1, data_get($file->summary, 'carton_total_rows_skipped'));
        // 100 + 80 (same ASIN, two cartons) + 60 — the 180 subtotal row is NOT counted.
        $this->assertSame(240, data_get($file->summary, 'units'));

        $delivery = Delivery::sole();
        $this->assertSame('22161389743', $delivery->asn);
        $this->assertSame('Aug-01', $delivery->internal_ref);
        $this->assertTrue($delivery->has_interim);
        $this->assertSame(240, $delivery->units_interim);

        $line = PoLine::where('sku_id', 'B08TEST0001')->sole();
        $this->assertSame(180, $line->qty_booked);   // two carton rows summed
        $this->assertSame(0, $line->qty_shipped);
        $this->assertSame(PoLine::STATE_SCHEDULED, $line->line_state);
        $this->assertSame(0, $line->computeNotBooked());
    }

    /** §K: the ASN sits before or after the internal ref; both must parse. */
    public function test_the_asn_is_parsed_whichever_end_of_the_name_it_sits(): void
    {
        $this->ingest(
            FakeWorkbook::packingListFinal('Aug-01-22161389743'),
            UploadType::AmazonFinalPacking,
            'a.xlsx'
        );
        $this->assertSame('22161389743', Delivery::sole()->asn);
        $this->assertSame('Aug-01', Delivery::sole()->internal_ref);

        Delivery::query()->delete();
        ShipmentLine::query()->delete();

        $this->ingest(
            FakeWorkbook::packingListFinal('22161964743-Aug-02'),
            UploadType::AmazonFinalPacking,
            'b.xlsx'
        );
        $this->assertSame('22161964743', Delivery::sole()->asn);
        $this->assertSame('Aug-02', Delivery::sole()->internal_ref);
    }

    /** The final layout shifts every column and moves the banner — header names save us. */
    public function test_the_final_layout_is_read_despite_shifted_columns(): void
    {
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');

        $file = $this->ingest(
            FakeWorkbook::packingListFinal(),
            UploadType::AmazonFinalPacking,
            'PACKING LIST_22161964743-Aug-02 - Final.xlsx'
        );

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame(150, data_get($file->summary, 'units'));
        $this->assertSame(['BD-3269-774FV9FB'], data_get($file->summary, 'invoice_numbers'));

        $line = ShipmentLine::where('sku_id', 'B08TEST0001')->sole();
        $this->assertSame('BD-3269-774FV9FB', $line->invoice_number);
        $this->assertSame(Stage::Final, $line->stage);
        $this->assertSame('1-2', $line->carton);
    }

    /** §E: fill rate = shipped ÷ net accepted, accumulating across deliveries. */
    public function test_fill_rate_is_computed_across_two_deliveries(): void
    {
        $this->ingest(
            FakeWorkbook::singlePo([['B08TEST0001', 200, 200]]),
            UploadType::AmazonPoSingle,
            'PurchaseOrder_774FV9FB.xlsx'
        );

        foreach (['22161389743-Aug-01' => 90, '22161964743-Aug-02' => 60] as $name => $qty) {
            $this->ingest(
                (new FakeWorkbook)->sheet('Simple List', [
                    [null, null, null, 'Shipment Name: '.$name],
                    [null, null, null, 'Shipment Date: 03 Aug 2026 04:00 PM'],
                    [],
                    ['PO', 'ASIN', 'Model Number', 'Title', 'Qty', 'Carton', 'Unit Cost'],
                    ['774FV9FB', 'B08TEST0001', '0634562947130', 'Test product one', $qty, '1', 10.0],
                ]),
                UploadType::AmazonFinalPacking,
                "PACKING LIST_{$name}.xlsx"
            );
        }

        $line = PoLine::sole();
        $this->assertSame(150, $line->qty_shipped);
        $this->assertSame(200, $line->qty_net_accepted);
        $this->assertEqualsWithDelta(75.0, (float) $line->fill_rate_pct, 0.001);
        $this->assertSame(PoLine::STATE_DISPATCHED, $line->line_state);
    }

    /** §L: shortfall is interim minus final, once both stages exist. */
    public function test_shortfall_appears_once_both_stages_are_in(): void
    {
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');
        $this->ingest(FakeWorkbook::packingList(), UploadType::AmazonInterimPacking, 'i.xlsx');

        $delivery = Delivery::sole();
        $this->assertSame(0, $delivery->shortfall_units, 'No shortfall until the final arrives.');

        $this->ingest(
            (new FakeWorkbook)->sheet('Simple List', [
                [null, null, null, null, null, 'Shipment Name: Aug-01-22161389743'],
                [null, null, null, null, null, 'Shipment Date: 03 Aug 2026 02:00 PM'],
                [],
                ['PO', null, 'Invoice Number', 'ASIN', 'Model Number', 'Title', 'Qty', 'Carton', 'Unit Cost'],
                ['774FV9FB', 'BD-1-', 'BD-1-774FV9FB', 'B08TEST0001', '0634562947130', 'Test one', 170, '1', 10.0],
                ['774FV9FB', 'BD-1-', 'BD-1-774FV9FB', 'B08TEST0002', '0634562947131', 'Test two', 55, '3', 12.0],
            ]),
            UploadType::AmazonFinalPacking,
            'f.xlsx'
        );

        $delivery = Delivery::sole();
        $this->assertSame(240, $delivery->units_interim);
        $this->assertSame(225, $delivery->units_final);
        $this->assertSame(15, $delivery->shortfall_units);
    }

    /** §K: a packing list can arrive before its PO, and must link up afterwards. */
    public function test_a_packing_list_arriving_before_its_po_reconciles_when_the_po_lands(): void
    {
        $file = $this->ingest(FakeWorkbook::packingList(), UploadType::AmazonInterimPacking, 'early.xlsx');

        $this->assertSame(SourceFileStatus::Imported, $file->status, 'Stored, not rejected.');
        $this->assertSame(3, $file->rows_unmatched);
        $this->assertSame(3, ShipmentLine::unmatched()->count());

        $poFile = $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');

        $this->assertSame(0, ShipmentLine::unmatched()->count());
        $this->assertSame(3, data_get($poFile->summary, 'lines_reconciled'));

        $line = PoLine::where('sku_id', 'B08TEST0001')->sole();
        $this->assertSame(180, $line->qty_booked);
        $this->assertSame(PoLine::STATE_SCHEDULED, $line->line_state);
    }

    // --- Cancellations (§G) --------------------------------------------------

    public function test_a_cancellation_on_free_units_nets_off_accepted(): void
    {
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');

        $file = $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 180, 20]]),
            UploadType::AmazonCancellations,
            'Amazon_Cancellations_2026-08-02.xlsx'
        );

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame(20, data_get($file->summary, 'units_netted'));

        $cancellation = Cancellation::sole();
        $this->assertSame(CancellationResolution::Applied, $cancellation->resolution);
        $this->assertFalse($cancellation->is_unmatched);

        $line = PoLine::where('sku_id', 'B08TEST0001')->sole();
        $this->assertSame(180, $line->qty_accepted);
        $this->assertSame(20, $line->qty_cancelled_honoured);
        $this->assertSame(160, $line->qty_net_accepted, 'Net accepted drops by the cancelled units.');
    }

    /** The join is PO + ASIN. The same ASIN on a different PO must not be touched. */
    public function test_a_cancellation_for_a_different_po_does_not_net(): void
    {
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');

        $this->ingest(
            FakeWorkbook::cancellations([['9ZZZZZZZ', 'B08TEST0001', 180, 20]]),
            UploadType::AmazonCancellations,
            'c.xlsx'
        );

        $line = PoLine::where('sku_id', 'B08TEST0001')->sole();
        $this->assertSame(0, $line->qty_cancelled_honoured);
        $this->assertSame(180, $line->qty_net_accepted);
        $this->assertTrue(Cancellation::sole()->is_unmatched);
    }

    /** §G: clawing back units already booked stops and asks; nothing nets meanwhile. */
    public function test_a_cancellation_over_booked_units_is_parked_for_a_decision(): void
    {
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');
        $this->ingest(FakeWorkbook::packingList(), UploadType::AmazonInterimPacking, 'i.xlsx');

        // 180 accepted, 180 already booked — so nothing is free.
        $file = $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 180, 30]]),
            UploadType::AmazonCancellations,
            'c.xlsx'
        );

        $this->assertSame(1, data_get($file->summary, 'needs_decision'));
        $this->assertSame(0, data_get($file->summary, 'units_netted'));

        $cancellation = Cancellation::sole();
        $this->assertSame(CancellationResolution::NeedsDecision, $cancellation->resolution);
        $this->assertTrue($cancellation->requiresDecision());

        $line = PoLine::where('sku_id', 'B08TEST0001')->sole();
        $this->assertSame(180, $line->qty_net_accepted, 'Nothing is netted behind the user\'s back.');
    }

    /** §G: "Quantity Confirmed" disagreeing with our accepted figure warns, never blocks. */
    public function test_a_confirmed_quantity_mismatch_warns_but_still_imports(): void
    {
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');

        $file = $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 999, 20]]),
            UploadType::AmazonCancellations,
            'c.xlsx'
        );

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame(1, data_get($file->summary, 'confirmed_qty_mismatches'));
        $this->assertTrue(collect($file->warnings)->contains(
            fn ($w) => str_contains($w, 'Quantity Confirmed mismatch')
        ));
        $this->assertTrue(Cancellation::sole()->hasConfirmedQtyMismatch());
    }

    /** A cancellation arriving before its PO is stored, then nets when the PO lands. */
    public function test_a_cancellation_arriving_before_its_po_nets_once_the_po_arrives(): void
    {
        $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 180, 20]]),
            UploadType::AmazonCancellations,
            'c.xlsx'
        );

        $this->assertTrue(Cancellation::sole()->is_unmatched);

        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');

        $cancellation = Cancellation::sole()->fresh();
        $this->assertFalse($cancellation->is_unmatched);
        $this->assertNotNull($cancellation->po_line_id);
    }

    // --- Re-upload behaviour -------------------------------------------------

    /** A packing list is a snapshot: re-uploading replaces its lines, never doubles them. */
    public function test_re_uploading_a_packing_list_replaces_rather_than_doubles(): void
    {
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');

        $this->ingest(FakeWorkbook::packingList(), UploadType::AmazonInterimPacking, 'i.xlsx');
        $this->ingest(FakeWorkbook::packingList(), UploadType::AmazonInterimPacking, 'i.xlsx');

        $this->assertSame(1, Delivery::count());
        $this->assertSame(3, ShipmentLine::stage(Stage::Interim)->count());
        $this->assertSame(240, Delivery::sole()->units_interim);
    }

    /** Interim and final coexist on one delivery rather than overwriting each other. */
    public function test_interim_and_final_coexist_on_one_delivery(): void
    {
        $this->ingest(FakeWorkbook::amazonPo(), UploadType::AmazonPoBulk, 'POItemExport.xls', 'xls');
        $this->ingest(FakeWorkbook::packingList(), UploadType::AmazonInterimPacking, 'i.xlsx');
        $this->ingest(
            FakeWorkbook::packingListFinal('Aug-01-22161389743'),
            UploadType::AmazonFinalPacking,
            'f.xlsx'
        );

        $delivery = Delivery::sole();
        $this->assertTrue($delivery->has_interim);
        $this->assertTrue($delivery->has_final);
        $this->assertSame(240, $delivery->units_interim);
        $this->assertSame(150, $delivery->units_final);

        $this->assertSame(3, ShipmentLine::stage(Stage::Interim)->count());
        $this->assertSame(2, ShipmentLine::stage(Stage::Final)->count());
    }

    public function test_a_packing_list_with_no_readable_asn_fails_with_a_clear_message(): void
    {
        $file = $this->ingest(
            (new FakeWorkbook)->sheet('Simple List', [
                [null, null, null, 'Shipment Name: no number here'],
                [], [],
                ['PO', 'ASIN', 'Model Number', 'Title', 'Qty', 'Carton'],
                ['774FV9FB', 'B08TEST0001', '0634562947130', 'Test', 10, '1'],
            ]),
            UploadType::AmazonInterimPacking,
            'bad.xlsx'
        );

        $this->assertSame(SourceFileStatus::Failed, $file->status);
        $this->assertStringContainsString('11-digit ASN', $file->rejection_reason);
        $this->assertSame(0, ShipmentLine::count());
    }
}
