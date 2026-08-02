<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('master_sku_rows', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace', 20); // 'Amazon' | 'Noon'
            $table->string('sku_id', 64);      // ASIN or ZSKU/NIN - native platform id, never barcode
            $table->string('barcode', 64)->nullable()->index();
            $table->string('short_title')->nullable();
            $table->string('long_title')->nullable();
            $table->decimal('unit_cost', 12, 4)->nullable(); // admin/finance visibility only, enforced in app layer
            $table->string('source_file')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['marketplace', 'sku_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('master_sku_rows'); }
};
