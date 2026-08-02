<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('shipment_lines', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace', 20);
            $table->string('po_id', 64);
            $table->string('sku_id', 64);
            $table->enum('stage', ['interim', 'final']);
            $table->integer('qty')->default(0);
            $table->string('carton')->nullable();
            $table->string('shipment_name')->nullable();
            $table->date('shipment_date')->nullable();
            $table->string('model_number')->nullable();
            $table->string('title')->nullable();
            $table->string('source_file')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['marketplace', 'po_id', 'sku_id', 'stage']);
        });
    }
    public function down(): void { Schema::dropIfExists('shipment_lines'); }
};
