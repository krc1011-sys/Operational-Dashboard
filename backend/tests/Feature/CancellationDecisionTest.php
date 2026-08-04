<?php

namespace Tests\Feature;

use App\Enums\CancellationResolution;
use App\Enums\UploadType;
use App\Models\Cancellation;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\SourceFile;
use App\Models\User;
use App\Services\Upload\UploadService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeWorkbook;
use Tests\TestCase;

/**
 * The §G "deliver anyway or pull it" workflow.
 *
 * The situation these tests set up is always the same one: Amazon cancels units we have
 * already committed. The system refuses to guess, nets nothing, and asks. What is being
 * checked is that each answer moves exactly the right numbers - and that the answer to
 * "who may answer" is enforced on the server, not just hidden in the view.
 */
class CancellationDecisionTest extends TestCase
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

    private function user(string $role): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    private function ingest(FakeWorkbook $book, UploadType $type, string $filename, string $extension = 'xlsx'): SourceFile
    {
        $path = $this->tempFiles[] = $book->write($extension);

        return app(UploadService::class)->handle(
            new UploadedFile($path, $filename, null, null, true),
            $type,
            $this->user('Admin'),
        );
    }

    /**
     * 200 accepted, 200 booked into a delivery, of which $shipped have actually gone,
     * and then Amazon cancels 50. Nothing can be netted, so it lands in the queue.
     */
    private function parkedCancellation(int $shipped = 0, int $cancelled = 50): Cancellation
    {
        $this->ingest(
            FakeWorkbook::amazonPo([['774FV9FB', 'B08TEST0001', 200, 200]]),
            UploadType::AmazonPoBulk,
            'POItemExport.xls',
            'xls'
        );

        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', 200]], '2026-08-09'),
            UploadType::AmazonInterimPacking,
            'i.xlsx'
        );

        if ($shipped > 0) {
            $this->ingest(
                FakeWorkbook::shipment('22161389743-Aug-01', [['774FV9FB', 'B08TEST0001', $shipped]], '2026-08-09'),
                UploadType::AmazonFinalPacking,
                'f.xlsx'
            );
        }

        $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 200, $cancelled]]),
            UploadType::AmazonCancellations,
            'c.xlsx'
        );

        $cancellation = Cancellation::sole();
        $this->assertSame(CancellationResolution::NeedsDecision, $cancellation->resolution);

        return $cancellation;
    }

    // --- Who may see it, who may answer it -----------------------------------

    public function test_the_queue_is_visible_to_roles_with_the_cancelled_items_permission(): void
    {
        $this->parkedCancellation();

        foreach (['Admin', 'Finance', 'Procurement', 'Warehouse'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('cancellations.index'))
                ->assertOk()
                ->assertSee('774FV9FB')
                ->assertSee('Needs your decision');
        }

        // Sales has no business seeing cancelled items (§O).
        $this->actingAs($this->user('Sales'))
            ->get(route('cancellations.index'))
            ->assertForbidden();
    }

    /**
     * Answering is Admin-only for now: the eventual owner has not been decided, and the
     * answer commits us to shipping (or not) against Amazon's cancellation. Everyone
     * else watches the exposure without being able to act on it.
     */
    public function test_only_admin_can_answer_the_question(): void
    {
        $cancellation = $this->parkedCancellation();

        foreach (['Finance', 'Procurement', 'Warehouse'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('cancellations.index'))
                ->assertOk()
                // No decision form at all - not a disabled one.
                ->assertDontSee(route('cancellations.decide', $cancellation), false)
                ->assertSee('not answer it');

            $this->actingAs($this->user($role))
                ->post(route('cancellations.decide', $cancellation), ['choice' => 'delivered_anyway'])
                ->assertForbidden();

            $this->assertSame(CancellationResolution::NeedsDecision, $cancellation->fresh()->resolution);
        }

        $this->actingAs($this->user('Admin'))
            ->post(route('cancellations.decide', $cancellation), ['choice' => 'delivered_anyway'])
            ->assertRedirect();

        $this->assertSame(CancellationResolution::DeliveredAnyway, $cancellation->fresh()->resolution);
    }

    // --- The two answers ------------------------------------------------------

    /** §G: deliver anyway - the units stay accepted, count as delivered, and we flag the risk. */
    public function test_deliver_anyway_keeps_the_units_and_raises_the_chargeback_flag(): void
    {
        $cancellation = $this->parkedCancellation(shipped: 200);

        $this->actingAs($this->user('Admin'))
            ->post(route('cancellations.decide', $cancellation), [
                'choice' => 'delivered_anyway',
                'note' => 'Confirmed with Amazon by email.',
            ])
            ->assertRedirect();

        $cancellation = $cancellation->fresh();
        $this->assertSame(CancellationResolution::DeliveredAnyway, $cancellation->resolution);
        $this->assertSame(0, (int) $cancellation->qty_honoured);
        $this->assertSame(50, (int) $cancellation->qty_delivered_anyway);
        $this->assertSame('Confirmed with Amazon by email.', $cancellation->resolution_note);
        $this->assertNotNull($cancellation->resolved_by);
        $this->assertNotNull($cancellation->resolved_at);

        $line = PoLine::sole();
        $this->assertSame(0, $line->qty_cancelled_honoured);
        $this->assertSame(200, $line->qty_net_accepted, 'Nothing came off accepted.');
        $this->assertEqualsWithDelta(100.0, (float) $line->fill_rate_pct, 0.001, 'The line can still read 100%.');
        $this->assertTrue($line->has_chargeback_flag);
    }

    /** §G: pull it, with nothing shipped yet - the whole cancellation is honoured. */
    public function test_pull_it_nets_the_units_off_when_nothing_has_shipped(): void
    {
        $cancellation = $this->parkedCancellation();

        $this->actingAs($this->user('Admin'))
            ->post(route('cancellations.decide', $cancellation), ['choice' => 'pulled_back'])
            ->assertRedirect();

        $cancellation = $cancellation->fresh();
        $this->assertSame(CancellationResolution::PulledBack, $cancellation->resolution);
        $this->assertSame(50, (int) $cancellation->qty_honoured);
        $this->assertSame(0, (int) $cancellation->qty_delivered_anyway);

        $line = PoLine::sole();
        $this->assertSame(150, $line->qty_net_accepted);
        $this->assertFalse($line->has_chargeback_flag, 'Nothing was shipped against it.');
    }

    /**
     * The honest half-and-half case: 30 of the 50 cancelled units are already on their
     * way. "Pull it" cannot un-ship those, so they stay counted as delivered and they
     * alone raise the chargeback flag.
     */
    public function test_pull_it_cannot_pull_back_units_that_have_already_shipped(): void
    {
        $cancellation = $this->parkedCancellation(shipped: 180, cancelled: 50);

        $this->actingAs($this->user('Admin'))
            ->post(route('cancellations.decide', $cancellation), ['choice' => 'pulled_back'])
            ->assertRedirect();

        $cancellation = $cancellation->fresh();
        // Pullable = 200 accepted - 180 shipped = 20.
        $this->assertSame(20, (int) $cancellation->qty_honoured);
        $this->assertSame(30, (int) $cancellation->qty_delivered_anyway);
        $this->assertTrue($cancellation->isChargebackExposure());

        $line = PoLine::sole();
        $this->assertSame(180, $line->qty_net_accepted);
        $this->assertSame(180, $line->qty_shipped);
        $this->assertEqualsWithDelta(100.0, (float) $line->fill_rate_pct, 0.001);
        $this->assertTrue($line->has_chargeback_flag);
    }

    /** The decision can be what finally closes a PO (§L completion). */
    public function test_answering_the_question_can_complete_the_po(): void
    {
        $cancellation = $this->parkedCancellation(shipped: 150, cancelled: 50);

        $this->assertFalse(PurchaseOrder::sole()->is_complete, '150 of 200 shipped, 50 in limbo.');

        $this->actingAs($this->user('Admin'))
            ->post(route('cancellations.decide', $cancellation), ['choice' => 'pulled_back']);

        $po = PurchaseOrder::sole();
        $this->assertTrue($po->is_complete, 'Net accepted is now 150, and 150 shipped.');
        $this->assertNotNull($po->completed_on);
    }

    // --- Guard rails ----------------------------------------------------------

    public function test_a_cancellation_that_netted_automatically_cannot_be_decided(): void
    {
        $this->ingest(
            FakeWorkbook::amazonPo([['774FV9FB', 'B08TEST0001', 200, 200]]),
            UploadType::AmazonPoBulk,
            'POItemExport.xls',
            'xls'
        );

        // Nothing booked, so this one nets by itself and never needs asking about.
        $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 200, 20]]),
            UploadType::AmazonCancellations,
            'c.xlsx'
        );

        $cancellation = Cancellation::sole();
        $this->assertSame(CancellationResolution::Applied, $cancellation->resolution);

        $this->actingAs($this->user('Admin'))
            ->post(route('cancellations.decide', $cancellation), ['choice' => 'delivered_anyway'])
            ->assertSessionHas('error');

        $this->assertSame(CancellationResolution::Applied, $cancellation->fresh()->resolution);
        $this->assertSame(20, (int) $cancellation->fresh()->qty_honoured);
    }

    public function test_an_unknown_answer_is_rejected(): void
    {
        $cancellation = $this->parkedCancellation();

        $this->actingAs($this->user('Admin'))
            ->post(route('cancellations.decide', $cancellation), ['choice' => 'ignore_it'])
            ->assertSessionHasErrors('choice');
    }

    // --- Re-uploading the same cancellation file ------------------------------

    /** A decision survives the same file being uploaded again. */
    public function test_re_uploading_an_unchanged_row_keeps_the_decision(): void
    {
        $cancellation = $this->parkedCancellation();

        $this->actingAs($this->user('Admin'))
            ->post(route('cancellations.decide', $cancellation), ['choice' => 'delivered_anyway']);

        $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 200, 50]]),
            UploadType::AmazonCancellations,
            'c2.xlsx'
        );

        $cancellation = Cancellation::sole();
        $this->assertSame(CancellationResolution::DeliveredAnyway, $cancellation->resolution);
        $this->assertSame(50, (int) $cancellation->qty_delivered_anyway);
        $this->assertNotNull($cancellation->resolved_at);
    }

    /** But a changed quantity means the decision was made about different numbers. */
    public function test_re_uploading_a_changed_quantity_reopens_the_question(): void
    {
        $cancellation = $this->parkedCancellation();

        $this->actingAs($this->user('Admin'))
            ->post(route('cancellations.decide', $cancellation), ['choice' => 'delivered_anyway']);

        $file = $this->ingest(
            FakeWorkbook::cancellations([['774FV9FB', 'B08TEST0001', 200, 80]]),
            UploadType::AmazonCancellations,
            'c2.xlsx'
        );

        $this->assertSame(1, data_get($file->summary, 'decisions_reopened'));
        $this->assertTrue(collect($file->warnings)->contains(fn ($w) => str_contains($w, 'asked again')));

        $cancellation = Cancellation::sole();
        $this->assertSame(CancellationResolution::NeedsDecision, $cancellation->resolution);
        $this->assertNull($cancellation->resolved_at);
        $this->assertSame(0, (int) $cancellation->qty_delivered_anyway);
        $this->assertFalse(PoLine::sole()->has_chargeback_flag);
    }

    public function test_the_dashboard_says_how_many_are_waiting(): void
    {
        $this->parkedCancellation();

        $this->actingAs($this->user('Admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('waiting on you', false);
    }
}
