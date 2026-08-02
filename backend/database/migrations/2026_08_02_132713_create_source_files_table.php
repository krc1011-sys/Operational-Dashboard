<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('source_files', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('marketplace', 20);
            $table->string('doc_type', 20); // po | interim | final | cancelled | master
            $table->integer('row_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('source_files'); }
};
