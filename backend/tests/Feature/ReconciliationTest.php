<?php

namespace Tests\Feature;

use App\Enums\CancellationResolution;
use App\Enums\Marketplace;
use App\Enums\UploadType;
use App\Models\Cancellation;
use App\Models\Delivery;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\SourceFile;
use App\Models\User;
use App\Services\Import\Reconciler;
use App\Services\Upload\UploadService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeWorkbook;
use Tests\TestCase;

/**
 * The M4 reconciliation engine: turnaround, PO completion, and cancellations being
 * re-decided as the picture changes.
 *
 * Everything here goes through the real upload pipeline, so what is being tested is what
 * a user would actually get by uploading those files in that order.
 */
class ReconciliationTest extends TestCase
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
            tap(User::factory()->create())->assignRole('Admin'),
            $context,
        );
    }

    /** One PO, 200 units accepted, ordered on 3 August. */
    private function po(int $accepted = 200, string $orderDate = '2026-08-03'): SourceFile
    {
        return $this->ingest(
            FakeWorkbook::amazonPo([['774FV9FB', 'B08TEST0001', $accepted, $accepted]], $orderDate),
            UploadType::AmazonPoBulk,
            'POItemExport.xls',
            'xls'
        );
    }

    // --- Turnaround (§L) -----------------------------------------------------

    public function test_a_po_stays_open_until_everything_accepted_has_shipped(): void
    {
        $this->po();

        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 150]], '2026-08-09'),
            UploadType::AmazonFinalPacking,
            'f1.xlsx'
        );

        $po = PurchaseOrder::sole();
        $this->assertFalse($po->is_complete, '50 of the 200 units have not shipped.');
        $this->assertNull($po->completed_on);
        $this->assertNull($po->days_to_complete);
        // Responsiveness is measurable straight away, even while the PO is open.
        $this->assertSame('2026-08-09', $po->first_shipped_on->toDateString());
        $this->assertSame(6, $po->daysToFirstShipment());
    }

    public function test_a_po_completes_on_the_date_of_its_last_shipment(): void
    {
        $this->po();

        foreach ([['22161389743-Aug-01', 150, '2026-08-09'], ['22161964743-Aug-02', 50, '2026-08-27']] as [$name, $qty, $date]) {
            $this->ingest(
                FakeWorkbook::shipment($name, [['774FV9FB', 'B08TEST0001', $qty]], $date),
                UploadType::AmazonFinalPacking,
                "{$name}.xlsx"
            );
        }

        $po = PurchaseOrder::sole();
        $this->assertTrue($po->is_complete);
        $this->assertSame('2026-08-09', $po->first_shipped_on->toDateString());
        $this->assertSame('2026-08-27', $po->completed_on->toDateString());
        // The blueprint's own worked example: PO 3 Aug, completed 27 Aug = 24 days.
        $this->assertSame(24, $po->days_to_complete);
        $this->assertSame(24, $po->turnaroundDays());
        $this->assertNull($po->daysOpen(), 'A finished PO is not still counting.');
    }

    /** §L: 10 days is the benchmark; anything over it is flagged, open or not. */
    public function test_the_ten_day_benchmark_flags_slow_pos_including_open_ones(): void
    {
        $this->travelTo('2026-08-28');

        $this->po(orderDate: '2026-08-03');
        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 150]], '2026-08-09'),
            UploadType::AmazonFinalPacking,
            'f.xlsx'
        );

        $po = PurchaseOrder::sole();
        $this->assertSame(25, $po->daysOpen(), '"25 days and counting".');
        $this->assertTrue($po->isBreachingBenchmark());
        $this->assertTrue(PurchaseOrder::breachingBenchmark()->where('id', $po->id)->exists());
    }

    public function test_a_po_delivered_inside_the_benchmark_is_not_flagged(): void
    {
        $this->po();

        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 200]], '2026-08-09'),
            UploadType::AmazonFinalPacking,
            'f.xlsx'
        );

        $po = PurchaseOrder::sole();
        $this->assertTrue($po->is_complete);
        $this->assertSame(6, $po->days_to_complete);
        $this->assertFalse($po->isBreachingBenchmark());
        $this->assertFalse(PurchaseOrder::breachingBenchmark()->where('id', $po->id)->exists());
    }

    /** Being booked is not being shipped - an interim must not close a PO. */
    public function test_an_interim_packing_list_does_not_complete_a_po(): void
    {
        $this->po();

        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 200]], '2026-08-09'),
            UploadType::AmazonInterimPacking,
            'i.xlsx'
        );

        $po = PurchaseOrder::sole();
        $this->assertFalse($po->is_complete);
        $this->assertNull($po->first_shipped_on);
        $this->assertSame(PoLine::STATE_SCHEDULED, PoLine::sole()->line_state);
    }

    /**
     * §L: "completion = shipped >= net accepted, OR the remainder is cancelled". The PO
     * was not complete on the day of the last shipment - it became complete on the day
     * the rest was cancelled.
     */
    public function test_a_po_closed_by_cancelling_the_remainder_completes_on_the_cancellation_date(): void
    {
        $this->travelTo('2026-08-20');

        $this->po();
        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 150]], '2026-08-09'),
            UploadType::AmazonFinalPacking,
            'f.xlsx'
        );

        $this->assertFalse(PurchaseOrder::sole()->is_complete);

        // The other 50 were never booked, so this nets automatically.
        $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 200, 50]]),
            UploadType::AmazonCancellations,
            'c.xlsx'
        );

        $po = PurchaseOrder::sole();
        $this->assertTrue($po->is_complete);
        $this->assertSame('2026-08-20', $po->completed_on->toDateString());
        $this->assertSame(17, $po->days_to_complete);
        $this->assertSame(150, PoLine::sole()->qty_net_accepted);
        $this->assertEqualsWithDelta(100.0, (float) PoLine::sole()->fill_rate_pct, 0.001);
    }

    /**
     * A delivery with no date on the sheet still counts. We know it shipped; the upload
     * day stands in, and the delivery says so rather than pretending it is exact.
     */
    public function test_a_final_with_no_date_falls_back_to_the_day_it_was_uploaded(): void
    {
        $this->travelTo('2026-08-15');

        $this->po();
        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 200]], null),
            UploadType::AmazonFinalPacking,
            'f.xlsx'
        );

        $delivery = Delivery::sole();
        $this->assertNull($delivery->delivered_on);
        $this->assertTrue($delivery->fulfilmentDateIsInferred());
        $this->assertSame('2026-08-15', $delivery->fulfilmentDate()->toDateString());
        $this->assertSame('2026-08-15', PurchaseOrder::sole()->completed_on->toDateString());
    }

    /**
     * The real single-PO export has no order date - only a future delivery window,
     * which is useless as the day the PO was raised. So it can be typed at upload,
     * exactly as the PO number already can.
     */
    public function test_the_po_date_can_be_typed_in_for_the_format_that_lacks_it(): void
    {
        $this->ingest(
            FakeWorkbook::singlePo([['B08TEST0001', 200, 200]]),
            UploadType::AmazonPoSingle,
            'PurchaseOrder_774FV9FB.xlsx',
            context: ['order_date' => '2026-08-03']
        );

        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 200]], '2026-08-09'),
            UploadType::AmazonFinalPacking,
            'f.xlsx'
        );

        $po = PurchaseOrder::sole();
        $this->assertSame('2026-08-03', $po->order_date->toDateString());
        $this->assertSame(6, $po->days_to_complete);
    }

    /** A typed date is a fallback, never an override - the file always wins. */
    public function test_a_typed_po_date_never_overrides_one_the_file_carries(): void
    {
        $this->ingest(
            FakeWorkbook::amazonPo([['774FV9FB', 'B08TEST0001', 200, 200]], '2026-08-03'),
            UploadType::AmazonPoBulk,
            'POItemExport.xls',
            'xls',
            ['order_date' => '2026-01-01']
        );

        $this->assertSame('2026-08-03', PurchaseOrder::sole()->order_date->toDateString());
    }

    /** No date anywhere: the PO still completes, it just has no day count. */
    public function test_a_po_with_no_date_completes_without_a_turnaround_figure(): void
    {
        $this->ingest(
            FakeWorkbook::singlePo([['B08TEST0001', 200, 200]]),
            UploadType::AmazonPoSingle,
            'PurchaseOrder_774FV9FB.xlsx'
        );

        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 200]], '2026-08-09'),
            UploadType::AmazonFinalPacking,
            'f.xlsx'
        );

        $po = PurchaseOrder::sole();
        $this->assertNull($po->order_date);
        $this->assertTrue($po->is_complete);
        $this->assertSame('2026-08-09', $po->completed_on->toDateString());
        $this->assertNull($po->days_to_complete, 'Nothing to measure from - and we do not invent one.');
        $this->assertFalse($po->isBreachingBenchmark());
    }

    // --- Cancellations being re-decided (§G) ---------------------------------

    /**
     * The upload screen promises that a cancellation for a PO we do not hold yet "will
     * net automatically once that PO arrives". This is that promise.
     */
    public function test_a_cancellation_that_arrived_first_nets_itself_when_the_po_lands(): void
    {
        $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 200, 20]]),
            UploadType::AmazonCancellations,
            'c.xlsx'
        );

        $this->assertSame(0, (int) Cancellation::sole()->qty_honoured, 'Nothing to net against yet.');

        $this->po();

        $cancellation = Cancellation::sole();
        $this->assertFalse($cancellation->is_unmatched);
        $this->assertSame(CancellationResolution::Applied, $cancellation->resolution);
        $this->assertSame(20, (int) $cancellation->qty_honoured);
        $this->assertSame(180, PoLine::sole()->qty_net_accepted, 'It really came off accepted.');
    }

    /**
     * The ordering that matters most during rollout: the packing list and the
     * cancellation both arrive before the PO they belong to. When the PO finally lands,
     * the booked units must be counted BEFORE the cancellation is judged - otherwise it
     * would look free and net itself off units that are already on a truck.
     */
    public function test_when_everything_arrives_before_the_po_the_booked_units_are_counted_first(): void
    {
        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 200]], '2026-08-09'),
            UploadType::AmazonInterimPacking,
            'i.xlsx'
        );

        $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 200, 20]]),
            UploadType::AmazonCancellations,
            'c.xlsx'
        );

        $this->po();

        $cancellation = Cancellation::sole();
        $this->assertSame(CancellationResolution::NeedsDecision, $cancellation->resolution);
        $this->assertSame(0, (int) $cancellation->qty_honoured);
        $this->assertSame(200, PoLine::sole()->qty_net_accepted, 'Still nothing netted behind the user\'s back.');
    }

    /**
     * The dangerous direction: units legitimately cancelled and netted must NOT be
     * quietly given back when a packing list turns up later.
     */
    public function test_units_already_netted_are_never_un_netted_by_a_later_packing_list(): void
    {
        $this->po();
        $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 200, 20]]),
            UploadType::AmazonCancellations,
            'c.xlsx'
        );

        $this->assertSame(180, PoLine::sole()->qty_net_accepted);

        // The warehouse books 180 - the cancellation was already honoured.
        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 180]], '2026-08-09'),
            UploadType::AmazonInterimPacking,
            'i.xlsx'
        );

        $cancellation = Cancellation::sole();
        $this->assertSame(CancellationResolution::Applied, $cancellation->resolution);
        $this->assertSame(20, (int) $cancellation->qty_honoured);
        $this->assertSame(180, PoLine::sole()->qty_net_accepted);
    }

    /** Recomputing is derived from scratch each time, so it must be repeatable. */
    public function test_recomputing_twice_changes_nothing(): void
    {
        $this->po();
        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 200]], '2026-08-09'),
            UploadType::AmazonFinalPacking,
            'f.xlsx'
        );

        $before = PurchaseOrder::sole()->only(['first_shipped_on', 'completed_on', 'days_to_complete', 'is_complete'])
            + PoLine::sole()->only(['qty_shipped', 'qty_net_accepted', 'fill_rate_pct', 'line_state']);

        app(Reconciler::class)
            ->recomputePoLinesFor(Marketplace::Amazon, ['774FV9FB']);

        $after = PurchaseOrder::sole()->only(['first_shipped_on', 'completed_on', 'days_to_complete', 'is_complete'])
            + PoLine::sole()->only(['qty_shipped', 'qty_net_accepted', 'fill_rate_pct', 'line_state']);

        $this->assertEquals($before, $after);
    }
}
