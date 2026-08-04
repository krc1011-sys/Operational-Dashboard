<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One physical delivery (blueprint §K, §Q).
 *
 * For Amazon the identity is the ASN - the 11-digit Advance Shipment Notice number
 * parsed out of the "Shipment Name" banner in cell D1 of the Simple List tab. The same
 * ASN appearing on an interim and a final packing list means they are the same delivery,
 * which is exactly what makes shortfall (interim - final) computable.
 *
 * For Noon there is no ASN in the file (it arrives by email), so `asn` is nullable and
 * `delivery_key` carries a generated stand-in. ~10% of Noon POs split into two ASNs;
 * because finals are summed per PO+NIN across deliveries, that works either way (§Q).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();

            $table->string('marketplace', 20);
            $table->string('channel', 30);

            // The ASN when we have one; null for Noon unless entered manually.
            $table->string('asn', 32)->nullable()->index();
            /*
             * Stable identity for the delivery: the ASN when present, otherwise a
             * generated key. Lets Amazon and Noon share one table and one unique index.
             */
            $table->string('delivery_key', 96);

            $table->string('internal_ref', 40)->nullable();  // e.g. "Aug-01" - a label, NOT a date
            $table->string('shipment_name_raw')->nullable(); // the untouched banner text

            /*
             * The banner "Shipment Date" is PLANNED/PROVISIONAL only - Amazon reschedules
             * per FC capacity. Stored, shown as "may change", with no hard logic wired
             * to it (§K). Actual fulfilment date lives in `delivered_on`.
             */
            $table->date('planned_date')->nullable();
            $table->date('delivered_on')->nullable()->index();
            $table->boolean('delivery_date_is_manual')->default(false);

            // Not in the packing list - derived from the delivery's POs, which should all
            // share one FC. A mismatch is a data-quality warning, not a hard error (§K).
            $table->string('fc_code', 40)->nullable()->index();
            $table->boolean('has_fc_conflict')->default(false);

            // --- Stage tracking -------------------------------------------------
            $table->boolean('has_interim')->default(false);
            $table->boolean('has_final')->default(false);
            $table->timestamp('interim_uploaded_at')->nullable();
            $table->timestamp('final_uploaded_at')->nullable();
            $table->foreignId('interim_source_file_id')->nullable()->constrained('source_files')->nullOnDelete();
            $table->foreignId('final_source_file_id')->nullable()->constrained('source_files')->nullOnDelete();

            // --- Cached totals, recomputed at M4 (§L) ---------------------------
            $table->integer('units_interim')->default(0);
            $table->integer('units_final')->default(0);
            $table->decimal('value_interim', 16, 4)->default(0);
            $table->decimal('value_final', 16, 4)->default(0);   // the final's "Invoice value"
            $table->integer('shortfall_units')->default(0);      // interim - final
            $table->decimal('shortfall_value', 16, 4)->default(0);
            $table->string('currency', 8)->default('AED');

            // Nice-to-have from the appointment letter, manual entry only (§D).
            $table->unsignedSmallInteger('pallet_count')->nullable();
            $table->unsignedSmallInteger('carton_count')->nullable();

            $table->timestamps();

            $table->unique(['marketplace', 'delivery_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
