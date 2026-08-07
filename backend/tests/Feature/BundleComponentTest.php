<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelEconomics;
use App\Models\User;
use App\Services\Margin\NetMarginEngine;
use App\Services\Margin\SkuMargin;
use App\Services\Reporting\FilterSet;
use App\Support\MoneyGate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Bundle component (not sold standalone)" — M8.
 *
 * Some products are never sold on their own: they go into a bundle, and the bundle is
 * what carries a price. Their channel row has a REAL COST against a selling price that
 * was never charged, so the engine's margin for them is arithmetic over a fiction.
 * `BD07903074` was the worst case — it topped the losing-SKU list at −33.31%, a phantom
 * loss for a product that has never been sold at a loss because it has never been sold.
 *
 * ═══ WHAT THE FLAG DOES AND, MORE IMPORTANTLY, WHAT IT DOES NOT ═══
 *
 * It changes NO figure. The product keeps every cost and purchase number it has,
 * everywhere. What it changes is RANKING: the product is held out of the margin
 * league table and the loss watchlist, where a meaningless percentage crowds out a real
 * one, and its margin reads "N/A — bundle component" instead of a number nobody should
 * act on. Both halves are tested, because a flag that quietly hid the cost as well would
 * be worse than the phantom loss it was meant to fix.
 */
class BundleComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operon.money_pin' => '4321']);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function unlockedAdmin(): User
    {
        $admin = tap(User::factory()->create())->assignRole('Admin');
        $this->actingAs($admin)->post('/money-pin', ['pin' => '4321']);

        return $admin;
    }

    /**
     * A product priced so that it loses money — which is exactly the shape BD07903074
     * has: a real cost against a selling price that no customer ever paid.
     */
    private function lossMaker(string $code, bool $bundleComponent = false): Product
    {
        $product = Product::create([
            'company_product_code' => $code,
            'name' => 'Component '.$code,
            'product_cost' => 4,
            'is_active' => true,
            'is_bundle_component' => $bundleComponent,
        ]);

        $economics = ProductChannelEconomics::create([
            'product_id' => $product->id,
            'channel' => 'amazon_retail',
            'rsp_ex_vat' => 4.0,
            'invoice_pct_of_rsp' => 0.9019,
            'net_pct_of_invoice' => 0.78,
            'product_cost' => 4,
            'currency' => 'AED',
        ]);

        NetMarginEngine::apply($economics)->save();

        return $product;
    }

    /** A healthy product, so the watchlist has something real to report. */
    private function earner(string $code): Product
    {
        $product = Product::create([
            'company_product_code' => $code,
            'name' => 'Earner '.$code,
            'product_cost' => 4,
            'is_active' => true,
            'is_bundle_component' => false,
        ]);

        $economics = ProductChannelEconomics::create([
            'product_id' => $product->id,
            'channel' => 'amazon_retail',
            'rsp_ex_vat' => 30.0,
            'invoice_pct_of_rsp' => 0.9019,
            'net_pct_of_invoice' => 0.78,
            'product_cost' => 4,
            'currency' => 'AED',
        ]);

        NetMarginEngine::apply($economics)->save();

        return $product;
    }

    // =====================================================================

    /** Unflagged, it is a loss-maker — which is the state the flag exists to correct. */
    public function test_without_the_flag_it_reads_as_a_loss(): void
    {
        $this->lossMaker('BD07903074');

        $row = SkuMargin::blendsForProducts([Product::sole()->id])->first();

        $this->assertFalse($row['profitable']);
        $this->assertLessThan(0, $row['blend']['margin_pct']);
    }

    /**
     * THE HEADLINE: flagged, it leaves the losing-SKU list — and the real loss-maker
     * beside it stays. A watchlist that reported both would be no watchlist at all.
     */
    public function test_a_flagged_component_leaves_the_losing_sku_list(): void
    {
        $this->lossMaker('BD07903074', bundleComponent: true);
        $this->lossMaker('BD00000001');   // a genuine loss, not flagged
        $this->earner('BD00000002');

        $response = $this->actingAs($this->unlockedAdmin())
            ->get(route('money.index', ['view' => 'sku']))
            ->assertOk();

        // One losing SKU is counted, not two.
        $response->assertSee('BD00000001');
        $response->assertSee('losing money');

        $rows = SkuMargin::rows(SkuMargin::BOTH, new FilterSet, null);

        $losing = $rows->filter(fn ($r) => $r['profitable'] === false)->pluck('code');

        $this->assertContains('BD00000001', $losing);
        $this->assertNotContains('BD07903074', $losing, 'a phantom loss must not sit on the watchlist');
    }

    /** Its COST is untouched and still on screen — the flag hides a verdict, not data. */
    public function test_a_flagged_component_keeps_its_cost_everywhere(): void
    {
        $product = $this->lossMaker('BD07903074', bundleComponent: true);

        // The engine still computes everything: nothing about the figures changed.
        $row = SkuMargin::blendsForProducts([$product->id])->first();

        $this->assertNotNull($row['blend']['cogs']);
        $this->assertEqualsWithDelta(4.0, $row['blend']['cogs'], 0.01);

        // And the master catalog still shows what we paid for it.
        $this->actingAs($this->unlockedAdmin())
            ->get(route('master.index', ['q' => 'BD07903074']))
            ->assertOk()
            ->assertSee('BD07903074')
            ->assertSee('4.0000');
    }

    /** The margin reads N/A rather than a number, and says why. */
    public function test_the_margin_reads_not_applicable_rather_than_a_percentage(): void
    {
        $this->lossMaker('BD07903074', bundleComponent: true);

        $this->actingAs($this->unlockedAdmin())
            ->get(route('money.index', ['view' => 'sku']))
            ->assertOk()
            ->assertSee(Product::BUNDLE_MARGIN_LABEL, false)
            ->assertSee('bundle component');
    }

    /** No verdict, rather than a false one. */
    public function test_a_flagged_component_gets_no_verdict(): void
    {
        $product = $this->lossMaker('BD07903074', bundleComponent: true);

        $row = SkuMargin::blendsForProducts([$product->id])->first();

        $this->assertNull($row['profitable']);
        $this->assertTrue($row['bundle_component']);
    }

    /** It does not move the blended headline either — that is a ranking too. */
    public function test_a_flagged_component_is_out_of_the_blended_headline(): void
    {
        $this->earner('BD00000002');

        $before = $this->actingAs($this->unlockedAdmin())
            ->get(route('money.index', ['view' => 'sku']))->getContent();

        $this->lossMaker('BD07903074', bundleComponent: true);

        $after = $this->actingAs($this->unlockedAdmin())
            ->get(route('money.index', ['view' => 'sku']))->getContent();

        $extract = fn (string $html) => preg_match('/Blended margin.*?class="v">([^<]*)/s', $html, $m) ? trim($m[1]) : null;

        $this->assertNotNull($extract($before));
        $this->assertSame($extract($before), $extract($after),
            'a product that is never sold must not move the blended margin');
    }

    /** Still findable: flagged is not hidden. Somebody searching for it needs it. */
    public function test_a_flagged_component_still_appears_on_the_screen(): void
    {
        $this->lossMaker('BD07903074', bundleComponent: true);

        $this->actingAs($this->unlockedAdmin())
            ->get(route('money.index', ['view' => 'sku']))
            ->assertOk()
            ->assertSee('BD07903074');
    }

    // --- The toggle -------------------------------------------------------

    /** An Admin can flag one by hand from the master grid — only a person knows which. */
    public function test_an_admin_can_flag_a_product_from_the_master_grid(): void
    {
        $product = $this->lossMaker('BD07903074');
        $admin = $this->unlockedAdmin();

        $this->actingAs($admin)
            ->patchJson(route('master.products.update', $product), [
                'field' => 'is_bundle_component',
                'value' => '1',
            ])
            ->assertOk();

        $this->assertTrue($product->fresh()->is_bundle_component);

        // And can unflag it again.
        $this->actingAs($admin)
            ->patchJson(route('master.products.update', $product), [
                'field' => 'is_bundle_component',
                'value' => '0',
            ])
            ->assertOk();

        $this->assertFalse($product->fresh()->is_bundle_component);
    }

    /** The grid offers the toggle, and shows the state to everyone who may see the grid. */
    public function test_the_grid_shows_the_toggle(): void
    {
        $this->lossMaker('BD07903074', bundleComponent: true);

        $this->actingAs($this->unlockedAdmin())
            ->get(route('master.index'))
            ->assertOk()
            ->assertSee('Bundle')
            ->assertSee('is_bundle_component');
    }

    /** Writing it still needs the permission AND the PIN, like every other master edit. */
    public function test_flagging_needs_the_permission_and_the_pin(): void
    {
        $product = $this->lossMaker('BD07903074');

        // Admin without the PIN.
        $admin = tap(User::factory()->create())->assignRole('Admin');

        $this->actingAs($admin)
            ->patchJson(route('master.products.update', $product), [
                'field' => 'is_bundle_component', 'value' => '1',
            ])
            ->assertRedirect(route('money-pin.prompt'));

        $this->assertFalse((bool) $product->fresh()->is_bundle_component);

        // Warehouse holds no master-management permission at all.
        $warehouse = tap(User::factory()->create())->assignRole('Warehouse');

        $this->actingAs($warehouse)->post('/money-pin', ['pin' => '4321']);
        $this->actingAs($warehouse)
            ->patchJson(route('master.products.update', $product), [
                'field' => 'is_bundle_component', 'value' => '1',
            ])
            ->assertForbidden();

        $this->assertFalse((bool) $product->fresh()->is_bundle_component);
    }

    /** The known list seeds a NEW product; a later import never re-applies it. */
    public function test_the_config_list_names_the_known_component(): void
    {
        $this->assertContains('BD07903074', (array) config('operon.bundle_components'));
    }

    /** The gate is unchanged: nothing here is visible without the PIN. */
    public function test_the_flag_does_not_open_any_money_to_anyone_new(): void
    {
        $this->lossMaker('BD07903074', bundleComponent: true);

        $admin = tap(User::factory()->create())->assignRole('Admin');

        $this->actingAs($admin);
        $this->assertFalse(MoneyGate::canSeeMargin());

        $this->actingAs($admin)
            ->get(route('money.index', ['view' => 'sku']))
            ->assertRedirect(route('money-pin.prompt'));
    }
}
