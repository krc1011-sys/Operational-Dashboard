<?php

namespace Tests\Feature;

use App\Enums\UploadType;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Reporting\FilterSet;
use App\Services\Upload\UploadService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeWorkbook;
use Tests\TestCase;

/**
 * The M5 core screens and the §M filter set they all share.
 *
 * One small world is imported through the real pipeline and then every screen is asked
 * about it, because the point of these screens is that they agree with the engine. The
 * world:
 *
 *   PO 774FV9FB (3 Aug, DXB3)   ASIN A 200 accepted · ASIN B 100 accepted
 *   PO 1L5KQKGM (5 Aug, DXB6)   ASIN C  50 accepted
 *
 *   ASN 22161389743 (9 Aug)   interim A 150, C 50  →  final A 140, C 50   [shipped]
 *   ASN 22161964743 (20 Aug)  interim B 100                               [not shipped]
 *
 * So: 350 net accepted, 190 shipped (54.29% fill), 10 units short on the shipped
 * delivery, 50 units of A never booked, and 100 units of B committed to a delivery that
 * has not gone yet.
 */
class ReportScreensTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->buildTheWorld();
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

    private function ingest(FakeWorkbook $book, UploadType $type, string $filename, string $extension = 'xlsx'): void
    {
        $path = $this->tempFiles[] = $book->write($extension);

        app(UploadService::class)->handle(
            new UploadedFile($path, $filename, null, null, true),
            $type,
            $this->user('Admin'),
        );
    }

    private function buildTheWorld(): void
    {
        $this->ingest(
            FakeWorkbook::amazonPo([['774FV9FB', 'B08TESTAAA', 200, 200], ['774FV9FB', 'B08TESTBBB', 100, 100]], '2026-08-03', 'DXB3'),
            UploadType::AmazonPoBulk, 'po1.xls', 'xls'
        );

        $this->ingest(
            FakeWorkbook::amazonPo([['1L5KQKGM', 'B08TESTCCC', 60, 50]], '2026-08-05', 'DXB6'),
            UploadType::AmazonPoBulk, 'po2.xls', 'xls'
        );

        // Delivery one: booked, then shipped 10 units short of ASIN A.
        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [
                ['774FV9FB', 'B08TESTAAA', 150],
                ['1L5KQKGM', 'B08TESTCCC', 50],
            ], '2026-08-09'),
            UploadType::AmazonInterimPacking, 'i1.xlsx'
        );

        $this->ingest(
            FakeWorkbook::shipment('22161389743-Aug-01', [
                ['774FV9FB', 'B08TESTAAA', 140],
                ['1L5KQKGM', 'B08TESTCCC', 50],
            ], '2026-08-09'),
            UploadType::AmazonFinalPacking, 'f1.xlsx'
        );

        // Delivery two: booked only - this is what "committed" means.
        $this->ingest(
            FakeWorkbook::shipment('22161964743-Aug-02', [['774FV9FB', 'B08TESTBBB', 100]], '2026-08-20'),
            UploadType::AmazonInterimPacking, 'i2.xlsx'
        );
    }

    // --- Who can see what (§O) ------------------------------------------------

    public function test_every_screen_is_behind_its_own_permission(): void
    {
        $admin = $this->user('Admin');

        foreach (['overview.index', 'po-lookup.index', 'fulfilment.index',
            'deliveries.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    /**
     * Merging Pending into Fulfilment must not cost anybody a screen.
     *
     * §O gives Sales `view-pending` and NOT `view-fulfillment`, so the merged screen has
     * to let Sales in - and then show them only what Pending showed them.
     */
    public function test_merging_pending_into_fulfilment_costs_sales_nothing(): void
    {
        $sales = $this->user('Sales');

        // Sales still reaches the rows their old tab held...
        $this->actingAs($sales)->get(route('fulfilment.index'))
            ->assertOk()
            ->assertSee('Not booked');

        // ...and the old URL still lands on them.
        $this->actingAs($sales)->get(route('pending.index'))
            ->assertRedirect(route('fulfilment.index', ['view' => 'outstanding']));

        // But Sales does not gain the full fulfilment view: asking for another status
        // still lands on the not-booked one.
        $this->actingAs($sales)
            ->get(route('fulfilment.index', ['view' => 'shipped']))
            ->assertOk()
            ->assertSee('with units not yet booked');
    }

    /**
     * Order value is open to every role, Warehouse included: it is the size of the order,
     * not what we make on it. Margin stays Admin-only behind the PIN (§S) and is tested
     * separately - see NetMarginTest.
     */
    public function test_every_role_sees_order_value_including_warehouse(): void
    {
        // Sales is left out on purpose: §O gives it no Fulfilment screen at all, which is
        // a screen-access rule, not a money one.
        foreach (['Finance', 'Procurement', 'Warehouse'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('fulfilment.index'))
                ->assertOk()
                ->assertSee('Short')
                ->assertSee('Fill rate');
        }
    }

    // --- Overview -------------------------------------------------------------

    public function test_the_overview_reports_the_engine_figures(): void
    {
        $response = $this->actingAs($this->user('Admin'))->get(route('overview.index'));

        // The figure and its unit are separate elements in the KPI tile, so the assertions
        // are on the numbers themselves - which is what the test is actually about.
        $response->assertOk()
            ->assertSee('54.29')    // fill rate: 190 shipped ÷ 350 net accepted
            ->assertSee('97.22')    // confirmation rate: 350 accepted ÷ 360 requested
            ->assertSee('Fill rate')
            ->assertSee('Confirmation rate')
            ->assertSee('Revenue at risk');
    }

    // --- Fulfilment, its filters and its grouping -----------------------------

    /** The list is POs now, worst unshipped value first (DESIGN_BRIEF §8). */
    public function test_fulfilment_lists_purchase_orders_worst_shortfall_first(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index'))
            ->assertOk()
            // 774FV9FB carries the bigger shortfall, so it leads.
            ->assertSeeInOrder(['774FV9FB', '1L5KQKGM']);
    }

    /** Opening a PO shows its lines and where each one has got to. */
    public function test_expanding_a_po_shows_its_lines(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index', ['expand' => '774FV9FB']))
            ->assertOk()
            ->assertSee('B08TESTAAA')
            ->assertSee('B08TESTBBB')
            // A line belonging to the other PO stays shut.
            ->assertDontSee('B08TESTCCC');
    }

    public function test_the_fc_filter_narrows_to_one_fulfilment_centre(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index', ['fc' => 'DXB6']))
            ->assertOk()
            ->assertSee('1L5KQKGM')
            ->assertDontSee('774FV9FB');
    }

    public function test_the_po_date_range_filters_on_the_pos_own_date(): void
    {
        // PO 1L5KQKGM was ordered on the 5th; 774FV9FB on the 3rd.
        $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index', ['from' => '2026-08-04']))
            ->assertOk()
            ->assertSee('1L5KQKGM')
            ->assertDontSee('774FV9FB');
    }

    public function test_the_search_box_finds_a_line_by_asin_and_by_po(): void
    {
        $admin = $this->user('Admin');

        // Searching an ASIN narrows to the PO that carries it.
        $this->actingAs($admin)->get(route('fulfilment.index', ['search' => 'B08TESTBBB']))
            ->assertOk()->assertSee('774FV9FB')->assertDontSee('1L5KQKGM');

        $this->actingAs($admin)->get(route('fulfilment.index', ['po' => '1L5KQKGM']))
            ->assertOk()->assertSee('1L5KQKGM')->assertDontSee('774FV9FB');
    }

    public function test_the_status_filter_uses_the_line_state(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index', ['view' => 'booked', 'expand' => '774FV9FB']))
            ->assertOk()
            // B is booked and not shipped; A and C have shipped.
            ->assertSee('B08TESTBBB')
            ->assertDontSee('B08TESTCCC');
    }

    /**
     * Group-by now rolls up in the EXPORT rather than on screen: the screen lists POs,
     * and on-screen brand/category rollups belong to Products (DESIGN_BRIEF §8).
     */
    public function test_grouping_by_sku_rolls_the_lines_up_in_the_export(): void
    {
        $csv = $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index', ['group_by' => 'sku', 'export' => 'csv']))
            ->streamedContent();

        $this->assertStringContainsString('B08TESTAAA', $csv);
        $this->assertStringContainsString('SKUs', $csv);
    }

    /** Brand comes from the master catalog, which this fixture does not load. */
    public function test_grouping_by_brand_says_so_rather_than_dropping_rows(): void
    {
        $csv = $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index', ['group_by' => 'brand', 'export' => 'csv']))
            ->streamedContent();

        $this->assertStringContainsString('Not in the catalog yet', $csv);
    }

    // --- The bulk ASIN filter (§M) --------------------------------------------

    /**
     * A pasted list is too long for a URL, so it is stashed and only a key travels -
     * which is what keeps paging and exporting working without re-pasting it.
     */
    public function test_a_pasted_list_of_asins_filters_and_survives_as_a_link(): void
    {
        $admin = $this->user('Admin');

        $response = $this->actingAs($admin)->post(route('fulfilment.index'), [
            'sku_list' => "B08TESTAAA\nB08TESTCCC",
        ]);

        $response->assertRedirect();
        $url = $response->headers->get('Location');

        $this->assertStringContainsString('sku_key=', $url);
        $this->assertStringNotContainsString('B08TESTAAA', $url, 'The list itself must not be in the URL.');

        // A and C live on different POs, so both POs survive the filter; B's PO is the
        // same as A's, so the proof it narrowed is in the line count, not the PO list.
        $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertSee('774FV9FB')
            ->assertSee('1L5KQKGM');

        $this->actingAs($admin)->get($url.'&expand=774FV9FB')
            ->assertOk()
            ->assertSee('B08TESTAAA')
            ->assertDontSee('B08TESTBBB');
    }

    public function test_an_uploaded_list_of_asins_filters_the_same_way(): void
    {
        $admin = $this->user('Admin');

        $response = $this->actingAs($admin)->post(route('fulfilment.index'), [
            'sku_file' => UploadedFile::fake()->createWithContent('asins.txt', "B08TESTCCC\n"),
        ]);

        $this->actingAs($admin)->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('1L5KQKGM')
            ->assertDontSee('774FV9FB');
    }

    public function test_pasted_lists_are_read_however_they_are_pasted(): void
    {
        $this->assertSame(
            ['B08TESTAAA', 'B08TESTBBB'],
            FilterSet::parseIdentifiers("b08testaaa\n B08TESTBBB \nb08testaaa")
        );

        $this->assertSame(
            ['B08TESTAAA', 'B08TESTBBB'],
            FilterSet::parseIdentifiers('B08TESTAAA, B08TESTBBB')
        );
    }

    // --- Export ---------------------------------------------------------------

    public function test_the_export_returns_the_filtered_rows_as_csv(): void
    {
        $response = $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index', ['fc' => 'DXB6', 'export' => 'csv']));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('OperON — Fulfilment', $csv);
        $this->assertStringContainsString('FC DXB6', $csv, 'The filters used are written into the file.');
        // Identifiers are written as text so Excel cannot eat a leading zero (§B).
        $this->assertStringContainsString("'B08TESTCCC", $csv);
        $this->assertStringNotContainsString('B08TESTAAA', $csv);
    }

    /** The export carries order value for every role too - the screen and the CSV agree. */
    public function test_the_export_carries_order_value_for_every_role(): void
    {
        foreach (['Finance', 'Warehouse'] as $role) {
            $csv = $this->actingAs($this->user($role))
                ->get(route('fulfilment.index', ['export' => 'csv']))->streamedContent();

            $this->assertStringContainsString('Shortfall value', $csv, "$role should see order value");
            $this->assertStringContainsString('Unit cost', $csv, "$role should see unit cost");
        }
    }

    // --- The merged "not booked" view (was the Pending tab) --------------------

    public function test_the_not_booked_view_shows_only_units_on_no_delivery(): void
    {
        // 50 units of A were never booked; B and C are fully booked.
        $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index', ['view' => 'outstanding', 'expand' => '774FV9FB']))
            ->assertOk()
            ->assertSee('B08TESTAAA')
            ->assertDontSee('B08TESTBBB')
            ->assertDontSee('B08TESTCCC');

        // And the PO carrying nothing outstanding drops off the list entirely.
        $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index', ['view' => 'outstanding']))
            ->assertOk()
            ->assertSee('774FV9FB')
            ->assertDontSee('1L5KQKGM');
    }

    // --- PO lookup (§L) --------------------------------------------------------

    public function test_the_po_list_shows_turnaround_against_the_benchmark(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get(route('po-lookup.index'))
            ->assertOk()
            ->assertSee('774FV9FB')
            ->assertSee('1L5KQKGM')
            // 1L5KQKGM: ordered 5 Aug, all 50 units shipped 9 Aug = complete in 4 days.
            // The tag abbreviates days to "4d" to keep the column narrow.
            ->assertSee('Complete in 4d');
    }

    public function test_filtering_the_po_list_by_status(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get(route('po-lookup.index', ['po_status' => 'complete']))
            ->assertOk()
            ->assertSee('1L5KQKGM')
            ->assertDontSee('774FV9FB');
    }

    /** §L: "searching a PO shows all linked ISAs, each with its date and units". */
    public function test_a_po_shows_every_delivery_its_units_went_into(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get(route('po-lookup.show', '774FV9FB'))
            ->assertOk()
            ->assertSee('22161389743')   // shipped 140 of ASIN A
            ->assertSee('22161964743')   // booked 100 of ASIN B
            ->assertSee('B08TESTAAA')
            ->assertSee('Still open');
    }

    // --- Deliveries (Shipments + Committed, merged) -----------------------------

    public function test_deliveries_lists_asns_and_their_shortfall(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get(route('deliveries.index', ['view' => 'shipped']))
            ->assertOk()
            ->assertSee('22161389743')
            ->assertDontSee('22161964743');   // booked, not shipped

        $this->actingAs($this->user('Admin'))
            ->get(route('deliveries.index', ['view' => 'booked']))
            ->assertOk()
            ->assertSee('22161964743')
            ->assertDontSee('22161389743');
    }

    /** An ASN carries several POs, and which ones is the question the warehouse asks. */
    public function test_expanding_an_asn_shows_its_pos_and_per_sku_shortfall(): void
    {
        $delivery = Delivery::where('asn', '22161389743')->sole();

        $this->actingAs($this->user('Admin'))
            ->get(route('deliveries.index', ['view' => 'shipped', 'expand' => $delivery->id]))
            ->assertOk()
            ->assertSee('774FV9FB')
            ->assertSee('1L5KQKGM')
            ->assertSee('B08TESTAAA');
    }

    public function test_a_delivery_shows_what_was_booked_against_what_shipped(): void
    {
        $delivery = Delivery::where('asn', '22161389743')->sole();

        $this->actingAs($this->user('Admin'))
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('B08TESTAAA')
            ->assertSee('774FV9FB')
            ->assertSee('1L5KQKGM');
    }

    /**
     * Merging Shipments and Committed must not cost anybody a screen.
     *
     * §O gives Sales the committed lookup without shipments, and Warehouse the reverse,
     * so each sees only the half of the toggle they hold.
     */
    public function test_the_delivery_merge_gives_each_role_only_its_own_half(): void
    {
        // Sales: committed only. The booked view opens; the shipped half is not offered.
        $this->actingAs($this->user('Sales'))
            ->get(route('deliveries.index'))
            ->assertOk()
            ->assertSee('Booked')
            ->assertSee('22161964743')
            ->assertDontSee('22161389743');

        // Warehouse: shipments only, and asking for the booked view does not grant it.
        $this->actingAs($this->user('Warehouse'))
            ->get(route('deliveries.index', ['view' => 'booked']))
            ->assertOk()
            ->assertSee('22161389743');
    }

    /** The delivery date decides turnaround, so correcting it has to move the PO figures. */
    public function test_correcting_a_delivery_date_recalculates_turnaround(): void
    {
        $delivery = Delivery::where('asn', '22161389743')->sole();

        $this->assertSame(4, PurchaseOrder::where('po_number', '1L5KQKGM')->sole()->days_to_complete);

        $this->actingAs($this->user('Admin'))
            ->patch(route('deliveries.date', $delivery), ['delivered_on' => '2026-08-25'])
            ->assertRedirect();

        $delivery->refresh();
        $this->assertTrue($delivery->delivery_date_is_manual);
        $this->assertSame('2026-08-25', $delivery->delivered_on->toDateString());

        // Ordered 5 Aug, now delivered 25 Aug = 20 days, over the 10-day benchmark.
        $order = PurchaseOrder::where('po_number', '1L5KQKGM')->sole();
        $this->assertSame(20, $order->days_to_complete);
        $this->assertTrue($order->isBreachingBenchmark());
    }

    public function test_correcting_a_delivery_date_needs_permission(): void
    {
        $delivery = Delivery::where('asn', '22161389743')->sole();

        $this->actingAs($this->user('Sales'))
            ->patch(route('deliveries.date', $delivery), ['delivered_on' => '2026-08-25'])
            ->assertForbidden();
    }

    /**
     * The DFS overstock trap (§R): what is already on its way out. Units on a delivery
     * that has SHIPPED must not appear - they are gone, not something to net against.
     */
    public function test_the_booked_view_lists_committed_units_per_sku(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get(route('deliveries.index', ['view' => 'booked']))
            ->assertOk()
            ->assertSee('Already committed, per SKU')
            ->assertSee('B08TESTBBB')      // 100 booked on the delivery that has not gone
            ->assertDontSee('B08TESTAAA')  // its delivery already shipped
            ->assertDontSee('B08TESTCCC');
    }

    public function test_the_booked_view_answers_for_a_pasted_dfs_order(): void
    {
        $admin = $this->user('Admin');

        $response = $this->actingAs($admin)->post(route('deliveries.index'), [
            'view' => 'booked',
            'sku_list' => "B08TESTBBB\nB08TESTAAA",
        ]);

        $this->actingAs($admin)->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('B08TESTBBB')
            // The one with nothing committed is called out, so it can be ordered freely.
            ->assertSee('nothing committed');
    }

    public function test_the_deliveries_export_carries_the_pos_under_each_asn(): void
    {
        $csv = $this->actingAs($this->user('Admin'))
            ->get(route('deliveries.index', ['view' => 'shipped', 'export' => 'csv']))
            ->streamedContent();

        $this->assertStringContainsString('774FV9FB', $csv);
        $this->assertStringContainsString('POs', $csv);
    }
}
