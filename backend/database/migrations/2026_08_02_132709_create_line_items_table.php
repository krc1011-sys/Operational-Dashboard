<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('line_items', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace', 20);
            $table->string('po_id', 64);
            $table->string('sku_id', 64); // native id: ASIN or ZSKU/NIN - the reconciliation join key, never barcode
            $table->string('sku_id_type', 20)->nullable();
            $table->string('title')->nullable();
            $table->string('model_number')->nullable();
            $table->string('fc_raw')->nullable();
            $table->string('fc_code', 20)->nullable()->index();
            $table->string('fc_name')->nullable();
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable()->index();
            $table->date('expected_date')->nullable();
            $table->integer('qty_requested')->default(0);
            $table->integer('qty_accepted')->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->string('currency', 8)->default('AED');
            $table->string('source_file')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['marketplace', 'po_id', 'sku_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('line_items'); }
};
