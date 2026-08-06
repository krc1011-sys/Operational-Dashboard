<?php

namespace Tests\Feature;

use App\Enums\Marketplace;
use App\Enums\SourceFileStatus;
use App\Enums\Stage;
use App\Enums\UploadType;
use App\Models\Delivery;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\PurchaseOrder;
use App\Models\ShipmentLine;
use App\Models\SourceFile;
use App\Models\User;
use App\Services\Upload\UploadService;
use App\Support\Barcode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeWorkbook;
use Tests\TestCase;

/**
 * The M8 Noon importers (§Q).
 *
 * The real sample workbook is business data and cannot live in the repo, so these use a
 * stand-in with the same shape — the same four tabs, the same shifted picking layout, the
 * same leading-zero barcodes. The real file is checked by `operon:verify-samples`, whose
 * expected figures are the ones validated by hand: 72 lines, 6,431 units, AED 107,694.05,
 * one short line, 6,402 delivered, 99.55%.
 *
 * ╔═══════════════════════════════════════════════════════════════════════════════════╗
 * ║  WHAT THESE TESTS EXIST TO CATCH: NOON ANNOTATES ONLY THE EXCEPTIONS.             ║
 * ║  A line missing from the picking list was DELIVERED IN FULL. Read it the Amazon   ║
 * ║  way — as a positive record of what shipped — and the fill rate collapses.        ║
 * ╚═══════════════════════════════════════════════════════════════════════════════════╝
 */
class NoonImportTest extends TestCase
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

    private function ingest(
        FakeWorkbook $book,
        UploadType $type,
        string $filename = 'Al Samha NOONAUH01G (AUH01G) 287285145169960_V1.xlsx',
        array $context = []
    ): SourceFile {
        $path = $this->tempFiles[] = $book->write('xlsx');

        return app(UploadService::class)->handle(
            new UploadedFile($path, $filename, null, null, true),
            $type,
            $this->admin(),
            $context
        );
    }

    /** The order, then the delivery — the normal sequence. */
    private function ingestPoAndFinal(?array $order = null, ?array $picked = null): SourceFile
    {
        $this->ingest(FakeWorkbook::noon(order: $order), UploadType::NoonPo);

        return $this->ingest(
            FakeWorkbook::noon(order: $order, picked: $picked),
            UploadType::NoonFinalPicking,
            'Al Samha 287285145169960_V1 - Final.xlsx',
        );
    }

    // =====================================================================
    // 1. THE ORDER — Noon's "Packing List"
    // =====================================================================

    /**
     * On Noon the PACKING LIST is the order. Getting this backwards is the first
     * available mistake, so it is the first thing asserted.
     */
    public function test_the_packing_list_is_the_order(): void
    {
        $file = $this->ingest(FakeWorkbook::noon(), UploadType::NoonPo);

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame(4, $file->rows_imported);

        $po = PurchaseOrder::sole();
        $this->assertSame('287285145169960', $po->po_number);
        $this->assertSame(Marketplace::Noon, $po->marketplace);
        // The PO number comes from the tab NAMED for it, never from the filename (§T).
        $this->assertSame('2026-07-21', $po->order_date->toDateString());
        $this->assertSame('AED', $po->currency);
        $this->assertSame('LE1AZSSSOAE', $po->ship_to_fc);

        $line = PoLine::where('sku_id', 'Z8C550B36EECA72A2A7ACZ-1')->sole();
        $this->assertSame(221, $line->qty_accepted);
        $this->assertSame('nin', $line->sku_id_type);
    }

    /**
     * Noon has NO ACCEPT STEP — it orders what it orders. Requested and accepted are the
     * same number so that fill rate, net accepted and shortfall all keep working, and
     * confirmation rate reads 100% rather than dividing by zero.
     */
    public function test_noon_has_no_accept_step_so_requested_equals_accepted(): void
    {
        $this->ingest(FakeWorkbook::noon(), UploadType::NoonPo);

        foreach (PoLine::all() as $line) {
            $this->assertSame($line->qty_requested, $line->qty_accepted);
            $this->assertSame($line->qty_accepted, $line->qty_net_accepted);
        }

        // And there is NO confirmation rate to report: reporting 100% would advertise a
        // negotiation that never happened.
        $this->assertNull(PurchaseOrder::sole()->confirmationRate());
    }

    /**
     * The unit cost has to reproduce what Noon invoiced.
     *
     * "Final Cost" is the VAT-inclusive rate rounded to the fils for printing — 2.89
     * where the true rate is 2.8875 — so multiplying it back up does NOT give the line
     * total. On the real 72-line PO that rounding put the order 6.57 out. The rate is
     * therefore taken from the line total, and units × unit cost reconciles.
     */
    public function test_order_value_reconciles_with_the_files_own_line_totals(): void
    {
        $file = $this->ingest(FakeWorkbook::noon(), UploadType::NoonPo);

        // 8 x 2.75 + 6 x 36.35 + 221 x 27.95 + 1 x 27.95, all + 5% VAT.
        $expected = round((8 * 2.75 + 6 * 36.35 + 221 * 27.95 + 1 * 27.95) * 1.05, 2);

        $this->assertEqualsWithDelta($expected, $file->summary['order_value'], 0.02);

        $recomputed = PoLine::all()->sum(fn (PoLine $l) => $l->qty_accepted * (float) $l->unit_cost);

        $this->assertEqualsWithDelta($expected, $recomputed, 0.02,
            'units x unit cost must reproduce what Noon invoiced');
    }

    /** The order is checked against the sheet's own Sub Total rather than trusted. */
    public function test_it_cross_checks_the_sheets_own_sub_total(): void
    {
        $file = $this->ingest(FakeWorkbook::noon(), UploadType::NoonPo);

        $this->assertSame(8 + 6 + 221 + 1, $file->summary['units']);
        $this->assertSame($file->summary['units'], $file->summary['sheet_sub_total_units']);
    }

    /** A NIN in the catalog links the line to its product; the master's own Noon code. */
    public function test_lines_link_to_the_master_catalog_by_nin(): void
    {
        $product = Product::create(['company_product_code' => 'BD06422853', 'is_active' => true]);
        ProductIdentifier::create([
            'product_id' => $product->id,
            'marketplace' => Marketplace::Noon->value,
            'sku_id' => 'Z2BDF218C04567F51F081Z-1',
            'sku_id_type' => 'nin',
        ]);

        $file = $this->ingest(FakeWorkbook::noon(), UploadType::NoonPo);

        $this->assertSame($product->id, PoLine::where('sku_id', 'Z2BDF218C04567F51F081Z-1')->sole()->product_id);
        $this->assertSame(1, $file->summary['linked_to_catalog']);
        // The three with no catalog entry are reported, not silently dropped.
        $this->assertSame(3, $file->rows_unmatched);
    }

    /** A base "V1" file carries a picking tab too. Ignoring it silently would mislead. */
    public function test_it_says_when_the_same_workbook_also_holds_a_picking_list(): void
    {
        $file = $this->ingest(FakeWorkbook::noon(), UploadType::NoonPo);

        $this->assertSame(3, $file->summary['picking_rows_in_this_file']);
        $this->assertStringContainsString(
            'has NOT been imported',
            implode(' ', $file->warnings ?? [])
        );
    }

    // =====================================================================
    // 2. THE DELIVERY — the inverted rule
    // =====================================================================

    /**
     * ═══ THE TEST THIS WHOLE FILE EXISTS FOR ═══
     *
     * Four lines ordered (8 + 6 + 221 + 1 = 236). The picking list mentions three of
     * them and shorts one; the fourth it never mentions at all.
     *
     *   stated:   8 + 6 + 192 = 206
     *   omitted:  1 (delivered in full, silently)
     *   TOTAL:    207 of 236
     *
     * Read the Amazon way — only what the file lists — it would report 206 and lose a
     * line that went out perfectly. On the real PO that mistake costs 590 units and
     * turns a 99.55% fill rate into 90.37%.
     */
    public function test_a_line_missing_from_the_picking_list_was_delivered_in_full(): void
    {
        $file = $this->ingestPoAndFinal();

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame(236, $file->summary['ordered_units']);
        $this->assertSame(207, $file->summary['delivered_units']);
        $this->assertSame(29, $file->summary['shortfall_units']);

        // The split between what was stated and what was inferred is reported, because
        // the inferred half is the part a reader could not otherwise check.
        $this->assertSame(3, $file->summary['lines_stated_on_file']);
        $this->assertSame(1, $file->summary['lines_delivered_in_full_by_omission']);

        // The omitted line has a real delivered figure, not a zero.
        $omitted = PoLine::where('sku_id', 'ZE03BE2628042392137A5Z-1')->sole();
        $this->assertSame(1, $omitted->qty_accepted);
        $this->assertSame(1, $omitted->qty_shipped);
        // Shipped units are booked by definition - a Noon PO can go straight to a final
        // with no interim at all, and those units must not sit on the Not-booked tab.
        $this->assertSame(0, $omitted->qty_not_booked);
    }

    /** The exception Noon did annotate: delivered is the stated Qty, not the order. */
    public function test_a_stated_quantity_is_what_was_delivered(): void
    {
        $this->ingestPoAndFinal();

        $short = PoLine::where('sku_id', 'Z8C550B36EECA72A2A7ACZ-1')->sole();

        $this->assertSame(221, $short->qty_accepted);
        $this->assertSame(192, $short->qty_shipped);
        $this->assertEqualsWithDelta(86.88, (float) $short->fill_rate_pct, 0.01);
    }

    /** The short line is named in the summary — that is what a picking list is FOR. */
    public function test_the_short_line_is_reported_with_its_numbers(): void
    {
        $file = $this->ingestPoAndFinal();

        $this->assertCount(1, $file->summary['short_lines']);

        $short = $file->summary['short_lines'][0];
        $this->assertSame('716841215014', $short['barcode']);
        $this->assertSame(221, $short['ordered']);
        $this->assertSame(192, $short['delivered']);
        $this->assertSame(29, $short['short']);
        // "OG qty" is Noon restating the order, and it agrees with ours.
        $this->assertSame(221, $short['og_qty_on_file']);
    }

    /**
     * The two tabs are joined on BARCODE, and they do not write it the same way: the
     * packing tab has 642135123720, the picking tab 0642135123720. Joining on the raw
     * strings matches nothing — and an unmatched picking row looks exactly like a line
     * that was never delivered, so the failure would be silent and confident.
     */
    public function test_it_joins_the_two_tabs_across_a_leading_zero(): void
    {
        $this->ingestPoAndFinal();

        $line = PoLine::where('sku_id', 'Z2BDF218C04567F51F081Z-1')->sole();

        $this->assertSame(8, $line->qty_shipped, 'the leading zero must not break the join');
        // The display form keeps Noon's own spelling of it.
        $this->assertSame('642135123720', $line->barcode);
        $this->assertSame(Barcode::key('0642135123720'), Barcode::key('642135123720'));
    }

    /** The whole PO, reconciled. 207 of 236 is 87.71%. */
    public function test_the_po_reconciles_to_a_fill_rate(): void
    {
        $this->ingestPoAndFinal();

        $lines = PoLine::all();

        $this->assertSame(236, (int) $lines->sum('qty_net_accepted'));
        $this->assertSame(207, (int) $lines->sum('qty_shipped'));
        $this->assertEqualsWithDelta(87.71, $lines->sum('qty_shipped') / $lines->sum('qty_net_accepted') * 100, 0.01);

        // One line short means the PO is not complete, exactly as on Amazon.
        $this->assertFalse(PurchaseOrder::sole()->is_complete);
    }

    /** Everything delivered means a complete PO and a 100% fill. */
    public function test_a_fully_delivered_po_completes(): void
    {
        $this->ingestPoAndFinal(picked: [
            ['642135123720', 8, null],
            ['716841214895', 6, null],
            ['716841215014', 221, null],
        ]);

        $this->assertSame(236, (int) PoLine::sum('qty_shipped'));
        $this->assertTrue(PurchaseOrder::sole()->fresh()->is_complete);
    }

    /**
     * An EMPTY picking list is not "nothing was delivered" — on Noon it is "no exceptions",
     * which means everything went in full. The brief warns the interim may arrive empty.
     */
    public function test_an_empty_picking_list_means_everything_went_in_full(): void
    {
        $file = $this->ingestPoAndFinal(picked: []);

        $this->assertSame(236, $file->summary['delivered_units']);
        $this->assertSame(0, $file->summary['shortfall_units']);
        $this->assertSame(4, $file->summary['lines_delivered_in_full_by_omission']);
    }

    // =====================================================================
    // 3. LAYOUTS, STAGES AND DELIVERIES
    // =====================================================================

    /**
     * The interim layout is 7 columns with Barcodes in column 3; the final is 9 with an
     * UNLABELLED column in between, putting Barcodes in column 4. Same header names,
     * different positions — which is exactly why nothing is read by position (§K).
     */
    public function test_the_interim_and_final_layouts_differ_and_both_parse(): void
    {
        $this->ingest(FakeWorkbook::noon(), UploadType::NoonPo);

        $interim = $this->ingest(
            FakeWorkbook::noon(interimLayout: true),
            UploadType::NoonInterimPicking,
            'Al Samha 287285145169960_V1 - Interim.xlsx',
        );

        $this->assertSame(SourceFileStatus::Imported, $interim->status);
        $this->assertSame(207, $interim->summary['delivered_units']);
        $this->assertSame(207, (int) PoLine::sum('qty_booked'));
        $this->assertSame(0, (int) PoLine::sum('qty_shipped'), 'an interim books, it does not ship');
    }

    /** Interim and final are two stages of ONE delivery — a Noon PO is one-shot (§Q). */
    public function test_both_stages_land_on_one_delivery(): void
    {
        $this->ingest(FakeWorkbook::noon(), UploadType::NoonPo);
        $this->ingest(FakeWorkbook::noon(interimLayout: true), UploadType::NoonInterimPicking, 'a - Interim.xlsx');
        $this->ingest(FakeWorkbook::noon(), UploadType::NoonFinalPicking, 'a - Final.xlsx', ['delivery_date' => '2026-07-23']);

        $delivery = Delivery::sole();

        $this->assertSame('NOON:287285145169960', $delivery->delivery_key);
        $this->assertTrue($delivery->has_interim);
        $this->assertTrue($delivery->has_final);
        $this->assertSame(207, $delivery->units_final);
        $this->assertSame('2026-07-23', $delivery->fulfilmentDate()->toDateString());
        $this->assertFalse($delivery->fulfilmentDateIsInferred());
    }

    /**
     * A delivery's value must not read zero.
     *
     * Noon carries no "Invoice value" banner the way an Amazon packing list does, so the
     * value has to come from summing the line values — and it did not, because the
     * decimal-cast column reads as the string "0.00", which is truthy.
     */
    public function test_a_noon_delivery_is_worth_what_its_lines_are_worth(): void
    {
        $this->ingestPoAndFinal();

        $this->assertGreaterThan(0, (float) Delivery::sole()->value_final);
    }

    /** Re-uploading a stage replaces its lines; a file is a snapshot, not an increment. */
    public function test_re_uploading_a_stage_replaces_rather_than_doubles(): void
    {
        $this->ingestPoAndFinal();
        $this->ingestPoAndFinal();

        $this->assertSame(207, (int) PoLine::sum('qty_shipped'));
        $this->assertSame(1, Delivery::count());
        $this->assertSame(4, ShipmentLine::where('stage', Stage::Final->value)->count());
    }

    /**
     * A picking list uploaded before its PO still produces the right delivered figures:
     * the order it compares against comes from the workbook's OWN packing tab, not from
     * the database. That removes a whole class of upload-order bug.
     */
    public function test_a_picking_list_can_arrive_before_its_po(): void
    {
        $final = $this->ingest(FakeWorkbook::noon(), UploadType::NoonFinalPicking, 'a - Final.xlsx');

        $this->assertSame(SourceFileStatus::Imported, $final->status);
        $this->assertSame(207, $final->summary['delivered_units']);
        $this->assertSame(4, ShipmentLine::where('is_unmatched', true)->count());

        // ...and the PO arriving links them up (§K).
        $this->ingest(FakeWorkbook::noon(), UploadType::NoonPo);

        $this->assertSame(0, ShipmentLine::where('is_unmatched', true)->count());
        $this->assertSame(207, (int) PoLine::sum('qty_shipped'));
    }

    /** A delivered line that was never ordered is kept and queried, not dropped. */
    public function test_a_delivered_line_that_was_never_ordered_is_reported(): void
    {
        $file = $this->ingestPoAndFinal(picked: [
            ['642135123720', 8, null],
            ['999999999999', 5, null],
        ]);

        // Reported in Noon's own spelling, leading zero and all - that is what their
        // support desk will ask for.
        $this->assertContains('0999999999999', $file->summary['lines_not_on_the_order']);
        $this->assertStringContainsString('never ordered', implode(' ', $file->warnings ?? []));
    }

    // =====================================================================
    // 4. VALIDATION
    // =====================================================================

    /** Choosing the wrong Noon type is caught before anything is imported (§J). */
    public function test_an_amazon_file_is_rejected_as_a_noon_po(): void
    {
        $file = $this->ingest(FakeWorkbook::amazonPo(), UploadType::NoonPo, 'POItemExport.xlsx');

        $this->assertSame(SourceFileStatus::Rejected, $file->status);
        $this->assertStringContainsString('Packing List', $file->rejection_reason);
        $this->assertSame(0, PurchaseOrder::count());
    }

    /** The filename is informational — the PO number comes from inside the file (§T). */
    public function test_the_filename_is_not_trusted_for_the_po_number(): void
    {
        $this->ingest(FakeWorkbook::noon(poNumber: '999888777666'), UploadType::NoonPo, 'totally-unrelated-name.xlsx');

        $this->assertSame('999888777666', PurchaseOrder::sole()->po_number);
    }
}
