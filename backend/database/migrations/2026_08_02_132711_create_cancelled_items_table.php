<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('cancelled_items', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace', 20);
            $table->string('po_id', 64);
            $table->string('sku_id', 64);
            $table->string('title')->nullable();
            $table->integer('cancelled_qty')->default(0); // Amazon's 'Quantity Outstanding'
            $table->integer('quantity_confirmed')->nullable();
            $table->date('future_cancel_date')->nullable();
            $table->string('source_file')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['marketplace', 'po_id', 'sku_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('cancelled_items'); }
};
