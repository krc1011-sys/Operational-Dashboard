<?php

namespace Tests\Feature;

use App\Enums\UploadType;
use App\Http\Middleware\EnsureMoneyPinVerified;
use App\Models\MasterAnomaly;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProductChannelEconomics;
use App\Models\ProductIdentifier;
use App\Models\PurchaseOrder;
use App\Models\SourceFile;
use App\Models\User;
use App\Services\Upload\UploadService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The master catalog: what the import loads, what it refuses to guess about, and who is
 * allowed to see or change any of it.
 *
 * The fixture mirrors the real merged file's awkward parts on purpose - a product on
 * three channels, a code reused for two products, a Noon row holding an ASIN - because
 * those are the cases where a well-meaning "fix" would quietly corrupt margin.
 */
class MasterCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** The real file's header names, typos and all. */
    private const HEADERS = [
        'Company Product Code', 'Customer Code', 'Customer Name', 'Barcode',
        'Customer Product Code', 'Brand', 'Category', 'Product Description',
        'Product Short Description', 'RSP with VAT', 'RSP (With/Without VAT)',
        'Invoice Cost Price', 'Fulfilment Fees', 'Referal Fees', 'Warehosue / Storage Fees',
        'Cat Fees', 'Other Fees', 'Net Recievable in Hand', 'Platform Total Fees %',
        'Product Cost', 'Marketing', 'OPEX', 'Packaging Cost', 'Other Misc. Expenses',
        'COGS (Cost of Goods Sold)', 'Profit', 'Profit %', 'Margin %', 'Currency',
        'Cartons', 'Suppliers', 'Sub Category', 'Owner', 'Origin', 'Data_Flag',
    ];

    protected function row(array $overrides = []): array
    {
        return array_values(array_merge([
            'code' => 'BD00000001', 'customer_code' => '1F6RD',
            'customer_name' => 'Amazon UAE - VC - 1F6RD', 'barcode' => '0642135122853',
            'sku' => 'B0H41MS7WG', 'brand' => 'Brandsfinity', 'category' => 'Office and Stationary',
            'description' => 'Barcode Label', 'short' => 'Barcode Label',
            'rsp_vat' => 36.25, 'rsp_ex' => 34.523809523809526, 'icp' => 31.137,
            'ff' => 0, 'rf' => 0, 'sf' => 0, 'cf' => 0, 'of' => 0,
            'net_recv' => 24.286878571428574, 'fees_pct' => 0.296518,
            'cost' => 18, 'marketing' => 1.5568511904761906, 'opex' => 1.8215158928571429,
            'packaging' => 0.93, 'misc' => 0, 'cogs' => 22.308367083333334,
            'profit' => 1.97851148809524, 'profit_pct' => 0.0886892115727015,
            'margin_pct' => 0.08146421460775073, 'currency' => 'AED', 'cartons' => 6,
            'suppliers' => 'Sigma Middle East', 'sub' => 'Big Barcode', 'owner' => 'Afnaan',
            'origin' => 'UAE', 'flag' => null,
        ], $overrides));
    }

    protected function upload(array $rows): SourceFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Master');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = sys_get_temp_dir().'/master-test-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $file = app(UploadService::class)->handle(
            new UploadedFile($path, 'OperON_Master_Merged.xlsx', null, null, true),
            UploadType::MasterSheet,
            $this->user('Admin'),
        );

        @unlink($path);

        return $file;
    }

    // --- Loading ----------------------------------------------------------

    public function test_it_loads_a_product_with_its_identifier_and_economics(): void
    {
        $this->upload([$this->row()]);

        $product = Product::where('company_product_code', 'BD00000001')->firstOrFail();

        $this->assertSame('Brandsfinity', $product->brand);
        $this->assertSame('Big Barcode', $product->sub_category, 'the APL sub-category loads');
        $this->assertSame('Afnaan', $product->owner);
        $this->assertSame('UAE', $product->origin);
        $this->assertSame(['Sigma Middle East'], $product->supplierList());

        $this->assertDatabaseHas('product_identifiers', [
            'marketplace' => 'amazon', 'sku_id' => 'B0H41MS7WG', 'product_id' => $product->id,
        ]);

        $economics = $product->economics->firstWhere('channel.value', 'amazon_retail');
        $this->assertEqualsWithDelta(1.9785, (float) $economics->profit, 0.001);
    }

    /**
     * The shape that drove the schema: one product, three channels, different economics
     * on each, but ONE identifier per marketplace.
     */
    public function test_one_product_carries_separate_economics_per_channel(): void
    {
        $this->upload([
            $this->row(),
            $this->row(['customer_code' => 'TY7WK', 'customer_name' => 'Amazon UAE - DS - TY7WK',
                'fees_pct' => 0.15, 'net_recv' => null, 'profit' => null]),
            $this->row(['customer_code' => 'LE3WVRU3GAE', 'customer_name' => 'Noon Retail',
                'sku' => 'ZF6F5E382F65F702DF8CCZ-1', 'fees_pct' => 0.2356,
                'net_recv' => null, 'profit' => null]),
        ]);

        $product = Product::where('company_product_code', 'BD00000001')->firstOrFail();

        $this->assertCount(3, $product->economics, 'three channels');
        $this->assertCount(2, $product->identifiers,
            'one identifier per marketplace - the two Amazon channels share an ASIN');

        $retail = $product->economics->firstWhere('channel.value', 'amazon_retail');
        $dfs = $product->economics->firstWhere('channel.value', 'amazon_dfs');

        $this->assertGreaterThan((float) $retail->profit, (float) $dfs->profit,
            'lower platform fees leave more profit');
    }

    public function test_a_reupload_updates_rather_than_duplicates(): void
    {
        $this->upload([$this->row()]);
        $this->upload([$this->row(['cost' => 25])]);

        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductChannelEconomics::count());
        $this->assertSame(1, ProductIdentifier::count());
    }

    // --- What it refuses to guess about -----------------------------------

    /** BD62972744 in the real file: one code, two genuinely different products. */
    public function test_a_code_covering_two_products_is_flagged_and_still_loaded(): void
    {
        $this->upload([
            $this->row(['flag' => 'REVIEW: differing supplier costs 12.84 / 34.32']),
        ]);

        $anomaly = MasterAnomaly::where('kind', MasterAnomaly::KIND_CODE_COVERS_TWO_PRODUCTS)->firstOrFail();

        $this->assertSame(MasterAnomaly::SEVERITY_REVIEW, $anomaly->severity);
        $this->assertStringContainsString('12.84 / 34.32', $anomaly->message);
        $this->assertStringContainsString('unreliable', $anomaly->message);

        // Loaded anyway - flagging is not dropping.
        $this->assertDatabaseHas('products', ['company_product_code' => 'BD00000001']);
    }

    /** BD07965870 in the real file: a Noon row holding an ASIN. */
    public function test_a_noon_row_holding_an_asin_is_flagged_and_not_moved(): void
    {
        $this->upload([
            $this->row(['customer_code' => 'LE3WVRU3GAE', 'customer_name' => 'Noon Retail',
                'sku' => 'B0CV9TDGGW']),
        ]);

        $anomaly = MasterAnomaly::where('kind', MasterAnomaly::KIND_IDENTIFIER_SHAPE)
            ->where('severity', MasterAnomaly::SEVERITY_REVIEW)->firstOrFail();

        $this->assertStringContainsString('B0CV9TDGGW', $anomaly->message);
        $this->assertStringContainsString('not moved', $anomaly->message);

        // Stored as Noon, exactly as the file has it - NOT quietly reassigned to Amazon.
        $this->assertDatabaseHas('product_identifiers', [
            'marketplace' => 'noon', 'sku_id' => 'B0CV9TDGGW',
        ]);
        $this->assertDatabaseMissing('product_identifiers', [
            'marketplace' => 'amazon', 'sku_id' => 'B0CV9TDGGW',
        ]);
    }

    public function test_a_cost_that_differs_between_channels_is_flagged(): void
    {
        $this->upload([
            $this->row(),
            $this->row(['customer_code' => 'LE3WVRU3GAE', 'customer_name' => 'Noon Retail',
                'sku' => 'ZAAAZ-1', 'cost' => 43]),
        ]);

        $anomaly = MasterAnomaly::where('kind', MasterAnomaly::KIND_COST_DISAGREEMENT)->firstOrFail();

        $this->assertStringContainsString('18.00', $anomaly->message);
        $this->assertStringContainsString('43.00', $anomaly->message);
    }

    /** Casing is not a disagreement, and reporting it would bury the real ones. */
    public function test_a_brand_differing_only_in_casing_is_not_flagged(): void
    {
        $this->upload([
            $this->row(),
            $this->row(['customer_code' => 'LE3WVRU3GAE', 'customer_name' => 'Noon Retail',
                'sku' => 'ZAAAZ-1', 'brand' => 'BRANDSFINITY']),
        ]);

        $this->assertSame(0, MasterAnomaly::where('kind', MasterAnomaly::KIND_ATTRIBUTE_DISAGREEMENT)
            ->where('message', 'like', '%brand%')->count());
    }

    public function test_two_products_claiming_one_identifier_keeps_the_first_and_says_so(): void
    {
        $this->upload([
            $this->row(),
            $this->row(['code' => 'BD00000002']), // same ASIN, different product
        ]);

        $anomaly = MasterAnomaly::where('kind', MasterAnomaly::KIND_IDENTIFIER_CONFLICT)->firstOrFail();

        $this->assertStringContainsString('BD00000001', $anomaly->message);
        $this->assertSame(
            Product::where('company_product_code', 'BD00000001')->value('id'),
            ProductIdentifier::where('sku_id', 'B0H41MS7WG')->value('product_id'),
        );
    }

    public function test_an_unknown_channel_holds_the_row_rather_than_guessing(): void
    {
        $this->upload([$this->row(['customer_code' => 'TRADELING', 'customer_name' => 'Tradeling'])]);

        // The product is catalogued; its economics are not attributed to a guessed channel.
        $this->assertDatabaseHas('products', ['company_product_code' => 'BD00000001']);
        $this->assertSame(0, ProductChannelEconomics::count());
        $this->assertNotNull(MasterAnomaly::where('message', 'like', '%TRADELING%')->first());
    }

    /** A person's decision survives the next upload; open items are refreshed. */
    public function test_resolving_a_flag_survives_a_reupload(): void
    {
        $this->upload([$this->row(['flag' => 'REVIEW: differing supplier costs 1 / 2'])]);

        $anomaly = MasterAnomaly::firstOrFail();
        $anomaly->update(['resolved_at' => now()]);

        $this->upload([$this->row(['flag' => 'REVIEW: differing supplier costs 1 / 2'])]);

        $this->assertNotNull($anomaly->fresh()->resolved_at, 'the answered one is left alone');
    }

    // --- Linking back ------------------------------------------------------

    /** §K: rows loaded before the catalog knew their SKU link up when it arrives. */
    public function test_it_links_po_lines_that_arrived_before_the_catalog(): void
    {
        $po = PurchaseOrder::create([
            'marketplace' => 'amazon', 'po_number' => 'TESTPO1', 'channel' => 'amazon_retail',
        ]);

        $line = PoLine::create([
            'purchase_order_id' => $po->id,
            'marketplace' => 'amazon', 'po_number' => 'TESTPO1', 'sku_id' => 'B0H41MS7WG',
            'channel' => 'amazon_retail', 'qty_accepted' => 10, 'qty_net_accepted' => 10,
            'unit_cost' => 30, 'currency' => 'AED',
        ]);

        $this->assertNull($line->product_id);

        $this->upload([$this->row()]);

        $this->assertNotNull($line->fresh()->product_id, 'the PO line found its product');
    }

    // --- Who may see and change it ----------------------------------------

    public function test_the_catalog_is_visible_without_money_and_the_money_needs_the_pin(): void
    {
        $this->upload([$this->row()]);

        // Warehouse has view-master but no money lens: catalog yes, cost no.
        $this->actingAs($this->user('Warehouse'))
            ->get(route('master.index'))
            ->assertOk()
            ->assertSee('BD00000001')
            ->assertSee('Cost and margin are hidden')
            ->assertDontSee('Net receivable');

        // Admin without the PIN is in the same position on this screen.
        $this->actingAs($this->user('Admin'))
            ->get(route('master.index'))
            ->assertOk()
            ->assertSee('Cost and margin are hidden');
    }

    public function test_admin_with_the_pin_sees_the_economics(): void
    {
        $this->upload([$this->row()]);

        $this->actingAs($this->user('Admin'))
            ->withSession([EnsureMoneyPinVerified::SESSION_KEY => now()->timestamp])
            ->get(route('master.index'))
            ->assertOk()
            ->assertSee('Net receivable')
            ->assertDontSee('Cost and margin are hidden');
    }

    public function test_editing_needs_both_the_permission_and_the_pin(): void
    {
        $this->upload([$this->row()]);
        $economics = ProductChannelEconomics::firstOrFail();

        // Right role, no PIN.
        $this->actingAs($this->user('Admin'))
            ->patch(route('master.economics.update', $economics), ['field' => 'marketing', 'value' => 5])
            ->assertRedirect(route('money-pin.prompt'));

        // PIN but not Admin.
        $this->actingAs($this->user('Finance'))
            ->withSession([EnsureMoneyPinVerified::SESSION_KEY => now()->timestamp])
            ->patch(route('master.economics.update', $economics), ['field' => 'marketing', 'value' => 5])
            ->assertForbidden();

        $this->assertEqualsWithDelta(1.5569, (float) $economics->fresh()->marketing, 0.001);
    }

    /** Editing an input recomputes the answer; it never stores a typed profit. */
    public function test_an_edit_recomputes_the_margin(): void
    {
        $this->upload([$this->row()]);
        $economics = ProductChannelEconomics::firstOrFail();

        $this->actingAs($this->user('Admin'))
            ->withSession([EnsureMoneyPinVerified::SESSION_KEY => now()->timestamp])
            ->patchJson(route('master.economics.update', $economics), ['field' => 'marketing', 'value' => 5])
            ->assertOk()
            ->assertJsonPath('saved', true);

        $fresh = $economics->fresh();

        $this->assertEqualsWithDelta(25.7515, (float) $fresh->cogs, 0.001);
        $this->assertLessThan(0, (float) $fresh->profit, 'the extra marketing turns it loss-making');
        $this->assertTrue($fresh->is_manual, 'marked as hand-edited so a re-import can tell');
    }

    public function test_a_derived_figure_cannot_be_typed_in(): void
    {
        $this->upload([$this->row()]);
        $economics = ProductChannelEconomics::firstOrFail();

        $this->actingAs($this->user('Admin'))
            ->withSession([EnsureMoneyPinVerified::SESSION_KEY => now()->timestamp])
            ->patchJson(route('master.economics.update', $economics), ['field' => 'profit', 'value' => 999])
            ->assertStatus(422);

        $this->assertEqualsWithDelta(1.9785, (float) $economics->fresh()->profit, 0.001);
    }

    /** Deleting would orphan real history, so a product is retired instead. */
    public function test_removing_a_product_retires_it_and_keeps_the_history(): void
    {
        $this->upload([$this->row()]);
        $product = Product::firstOrFail();

        $this->actingAs($this->user('Admin'))
            ->withSession([EnsureMoneyPinVerified::SESSION_KEY => now()->timestamp])
            ->delete(route('master.products.destroy', $product))
            ->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false]);
    }

    public function test_the_export_withholds_money_from_roles_that_may_not_see_it(): void
    {
        $this->upload([$this->row()]);

        $warehouse = $this->actingAs($this->user('Warehouse'))
            ->get(route('master.index', ['export' => 'csv']))->streamedContent();

        $admin = $this->actingAs($this->user('Admin'))
            ->withSession([EnsureMoneyPinVerified::SESSION_KEY => now()->timestamp])
            ->get(route('master.index', ['export' => 'csv']))->streamedContent();

        $this->assertStringNotContainsString('Margin %', $warehouse);
        $this->assertStringContainsString('BD00000001', $warehouse, 'the catalog itself is fine to export');
        $this->assertStringContainsString('Margin %', $admin);
    }

    /** M5 built brand/category filters with nothing to fill them. This is that moment. */
    public function test_loading_the_catalog_switches_on_the_brand_and_category_filters(): void
    {
        $this->upload([$this->row()]);

        $this->actingAs($this->user('Admin'))
            ->get(route('fulfilment.index', ['group_by' => 'brand']))
            ->assertOk();

        $this->assertSame(['Brandsfinity'], Product::distinct()->pluck('brand')->all());
    }
}
