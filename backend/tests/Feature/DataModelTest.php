<?php

namespace Tests\Feature;

use App\Enums\CancellationResolution;
use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Enums\Stage;
use App\Models\Cancellation;
use App\Models\Delivery;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\PurchaseOrder;
use App\Models\ShipmentLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the M1 schema can express the scenarios the blueprint already validated on
 * real files. These are shape tests, not the engine itself (that is M4) - they exist so
 * a wrong table design is caught now rather than after the parsers are written.
 */
class DataModelTest extends TestCase
{
    use RefreshDatabase;

    private function po(string $number, array $attrs = []): PurchaseOrder
    {
        return PurchaseOrder::create(array_merge([
            'marketplace' => Marketplace::Amazon,
            'channel' => Channel::AmazonRetail,
            'po_number' => $number,
            'ship_to_fc' => 'DXB3',
            'order_date' => '2026-08-03',
        ], $attrs));
    }

    private function line(PurchaseOrder $po, string $asin, int $accepted, array $attrs = []): PoLine
    {
        return PoLine::create(array_merge([
            'purchase_order_id' => $po->id,
            'marketplace' => $po->marketplace,
            'channel' => $po->channel,
            'po_number' => $po->po_number,
            'sku_id' => $asin,
            'sku_id_type' => 'asin',
            'qty_requested' => $accepted,
            'qty_accepted' => $accepted,
            'unit_cost' => 10.0,
        ], $attrs));
    }

    private function delivery(string $asn, array $attrs = []): Delivery
    {
        return Delivery::create(array_merge([
            'marketplace' => Marketplace::Amazon,
            'channel' => Channel::AmazonRetail,
            'asn' => $asn,
            'delivery_key' => $asn,
            'fc_code' => 'DXB3',
        ], $attrs));
    }

    private function packingLine(Delivery $d, PoLine $l, Stage $stage, int $qty, array $attrs = []): ShipmentLine
    {
        return ShipmentLine::create(array_merge([
            'delivery_id' => $d->id,
            'marketplace' => $d->marketplace,
            'channel' => $d->channel,
            'stage' => $stage,
            'po_number' => $l->po_number,
            'sku_id' => $l->sku_id,
            'po_line_id' => $l->id,
            'qty' => $qty,
        ], $attrs));
    }

    /** Blueprint §E worked example: accepted 2,000; finals 480+500+700+300 = 1,980 -> 99%. */
    public function test_fill_rate_accumulates_across_multiple_deliveries(): void
    {
        $po = $this->po('774FV9FB');
        $line = $this->line($po, 'B08TESTASIN', 2000);

        foreach ([480, 500, 700, 300] as $i => $qty) {
            $this->packingLine($this->delivery('2216138974'.$i), $line, Stage::Final, $qty);
        }

        $line->qty_shipped = $line->sumStage(Stage::Final);

        $this->assertSame(1980, $line->qty_shipped);
        $this->assertSame(99.0, $line->computeFillRate());
        $this->assertSame(PoLine::STATE_DISPATCHED, $line->computeState());
    }

    /** §E: net accepted = accepted - honoured cancellations, and that is the denominator. */
    public function test_cancellation_reduces_the_fill_rate_denominator(): void
    {
        $po = $this->po('1L5KQKGM');
        $line = $this->line($po, 'B08CANCEL01', 1000);

        Cancellation::create([
            'marketplace' => Marketplace::Amazon,
            'channel' => Channel::AmazonRetail,
            'po_number' => $po->po_number,
            'sku_id' => $line->sku_id,
            'po_line_id' => $line->id,
            'qty_confirmed' => 1000,
            'qty_cancelled' => 200,
            'qty_honoured' => 200,
            'resolution' => CancellationResolution::Applied,
        ]);

        $line->qty_cancelled_honoured = 200;
        $line->qty_shipped = 800;

        $this->assertSame(800, $line->computeNetAccepted());
        $this->assertSame(100.0, $line->computeFillRate()); // 800 of a net-accepted 800
    }

    /** §G: the PO file's own cancelled column must never touch the maths. */
    public function test_po_file_cancelled_column_does_not_net_anything(): void
    {
        $po = $this->po('774NETTING');
        $line = $this->line($po, 'B08NETTING1', 500, ['qty_cancelled_po_file' => 300]);

        // Nothing in the cancellations table, so net accepted is untouched by the 300.
        $this->assertSame(500, $line->computeNetAccepted());
    }

    /** §G: a cancellation touching already-booked units must stop and ask. */
    public function test_cancellation_over_booked_units_requires_a_decision(): void
    {
        $po = $this->po('774DECIDE');
        $line = $this->line($po, 'B08DECIDE01', 100);
        $line->qty_booked = 90;
        $line->save();

        $safe = new Cancellation(['qty_cancelled' => 10]);
        $safe->setRelation('poLine', $line);
        $this->assertFalse($safe->requiresDecision(), '10 units are still free - net silently.');

        $risky = new Cancellation(['qty_cancelled' => 40]);
        $risky->setRelation('poLine', $line);
        $this->assertTrue($risky->requiresDecision(), '40 units would claw back booked stock.');

        $this->assertTrue(CancellationResolution::DeliveredAnyway->isChargebackExposure());
        $this->assertFalse(CancellationResolution::DeliveredAnyway->netsAccepted());
        $this->assertTrue(CancellationResolution::PulledBack->netsAccepted());
    }

    /** §K: "Carton total" rows are subtotals and must never be imported as items. */
    public function test_carton_total_rows_are_detected(): void
    {
        $this->assertTrue(ShipmentLine::isCartonTotalRow('Carton total'));
        $this->assertTrue(ShipmentLine::isCartonTotalRow('  CARTON TOTAL  '));
        $this->assertFalse(ShipmentLine::isCartonTotalRow('Carton total wipes 3-pack'));
        $this->assertFalse(ShipmentLine::isCartonTotalRow(null));
    }

    /** §K: same ASIN split across cartons just adds up - rows are kept individually. */
    public function test_one_asin_split_across_cartons_sums(): void
    {
        $po = $this->po('774CARTON');
        $line = $this->line($po, 'B08SPLIT001', 300);
        $delivery = $this->delivery('22161389743');

        $this->packingLine($delivery, $line, Stage::Interim, 100, ['carton' => '1']);
        $this->packingLine($delivery, $line, Stage::Interim, 100, ['carton' => '2']);
        $this->packingLine($delivery, $line, Stage::Interim, 50, ['carton' => '3-4']);

        $this->assertSame(250, $line->sumStage(Stage::Interim));
        $this->assertSame(0, $line->sumStage(Stage::Final));
        $line->qty_booked = 250;
        $this->assertSame(PoLine::STATE_SCHEDULED, $line->computeState());
        $this->assertSame(50, $line->computeNotBooked());
    }

    /** §K: a packing list can arrive before its PO. Store it, never drop it. */
    public function test_unmatched_packing_lines_are_stored_and_can_reconcile_later(): void
    {
        $delivery = $this->delivery('22161964743');

        $orphan = ShipmentLine::create([
            'delivery_id' => $delivery->id,
            'marketplace' => Marketplace::Amazon,
            'channel' => Channel::AmazonRetail,
            'stage' => Stage::Final,
            'po_number' => 'NOTYETLOADED',
            'sku_id' => 'B08ORPHAN01',
            'qty' => 120,
            'is_unmatched' => true,
        ]);

        $this->assertSame(1, ShipmentLine::unmatched()->count());

        // The PO turns up later and the line reconciles.
        $po = $this->po('NOTYETLOADED');
        $line = $this->line($po, 'B08ORPHAN01', 120);

        $orphan->update(['po_line_id' => $line->id, 'is_unmatched' => false]);

        $this->assertSame(0, ShipmentLine::unmatched()->count());
        $this->assertSame(120, $line->sumStage(Stage::Final));
    }

    /** §L shortfall, using the blueprint's verified DXB3 figures. */
    public function test_delivery_shortfall_in_units_and_money(): void
    {
        $delivery = $this->delivery('22161964743', [
            'units_interim' => 700,
            'units_final' => 641,
            'value_interim' => 14240.95,
            'value_final' => 13764.59,
        ]);

        $this->assertSame(59, $delivery->computeShortfallUnits());
        $this->assertEqualsWithDelta(476.36, $delivery->computeShortfallValue(), 0.001);
    }

    /** §S: one physical product, many channel-native ids, unified by Company Product Code. */
    public function test_a_product_unifies_an_asin_and_a_nin(): void
    {
        $product = Product::create([
            'company_product_code' => 'BD12345',
            'name' => 'Test product',
            'brand' => 'TestBrand',
        ]);

        ProductIdentifier::create([
            'product_id' => $product->id,
            'marketplace' => Marketplace::Amazon,
            'sku_id' => 'B08UNIFY001',
            'sku_id_type' => 'asin',
        ]);
        ProductIdentifier::create([
            'product_id' => $product->id,
            'marketplace' => Marketplace::Noon,
            'sku_id' => 'Z8C550ABCZ-1',
            'sku_id_type' => 'nin',
        ]);

        $this->assertSame($product->id, ProductIdentifier::resolveProductId(Marketplace::Amazon, 'B08UNIFY001'));
        $this->assertSame($product->id, ProductIdentifier::resolveProductId(Marketplace::Noon, 'Z8C550ABCZ-1'));
        $this->assertNull(ProductIdentifier::resolveProductId(Marketplace::Amazon, 'B08NEVERSEEN'));
        $this->assertCount(2, $product->identifiers);
    }

    /** §C: re-uploading a PO file updates in place rather than duplicating. */
    public function test_re_uploading_a_po_line_upserts(): void
    {
        $po = $this->po('774UPSERT');
        $this->line($po, 'B08UPSERT01', 100);

        PoLine::updateOrCreate(
            ['marketplace' => Marketplace::Amazon->value, 'po_number' => '774UPSERT', 'sku_id' => 'B08UPSERT01'],
            ['qty_accepted' => 150, 'purchase_order_id' => $po->id, 'channel' => Channel::AmazonRetail]
        );

        $this->assertSame(1, PoLine::where('po_number', '774UPSERT')->count());
        $this->assertSame(150, PoLine::where('po_number', '774UPSERT')->value('qty_accepted'));
    }

    /** §L: confirmation rate is Amazon-only; Noon has no accept-less-than-ordered step (§Q). */
    public function test_confirmation_rate_is_amazon_only(): void
    {
        $amazon = $this->po('774CONFIRM');
        $this->line($amazon, 'B08CONF0001', 100, ['qty_requested' => 100, 'qty_accepted' => 85]);
        $this->assertSame(85.0, $amazon->confirmationRate());

        $noon = PurchaseOrder::create([
            'marketplace' => Marketplace::Noon,
            'channel' => Channel::NoonRetail,
            'po_number' => 'NOON-001',
        ]);
        $this->assertNull($noon->confirmationRate());
    }

    /** §Q: Noon deliveries have no ASN in the file, so a stand-in key is generated. */
    public function test_noon_deliveries_work_without_an_asn(): void
    {
        $key = Delivery::keyFor(Marketplace::Noon, null, 'NOON-001');
        $this->assertSame('NOON:NOON-001', $key);
        $this->assertSame('22161389743', Delivery::keyFor(Marketplace::Amazon, '22161389743', 'ignored'));

        $delivery = Delivery::create([
            'marketplace' => Marketplace::Noon,
            'channel' => Channel::NoonRetail,
            'asn' => null,
            'delivery_key' => $key,
            'delivered_on' => '2026-08-20',
            'delivery_date_is_manual' => true,
        ]);

        $this->assertNull($delivery->asn);
        $this->assertTrue($delivery->delivery_date_is_manual);
    }

    /** §R: DFS is a different channel on the same product spine, with no PO. */
    public function test_channels_know_whether_they_run_through_the_po_engine(): void
    {
        $this->assertTrue(Channel::AmazonRetail->hasPurchaseOrders());
        $this->assertTrue(Channel::NoonRetail->hasPurchaseOrders());
        $this->assertFalse(Channel::AmazonDfs->hasPurchaseOrders());

        $this->assertSame(Marketplace::Amazon, Channel::AmazonDfs->marketplace());
        $this->assertSame('asin', Marketplace::Amazon->skuIdType());
        $this->assertSame('nin', Marketplace::Noon->skuIdType());
    }
}
