<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('manual_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace', 20);
            $table->string('po_id', 64);
            $table->string('sku_id', 64);
            $table->integer('qty');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['marketplace', 'po_id', 'sku_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('manual_overrides'); }
};
