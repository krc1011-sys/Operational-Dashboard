<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upload audit log (blueprint §J).
 *
 * Every upload lands here first - before a single data row is written - so we always
 * know what was uploaded, by whom, whether it passed the fingerprint check for the
 * chosen type, and exactly what it produced. Also powers the §J freshness nudge
 * ("DFS not uploaded in 9 days") by holding the last import time per type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_files', function (Blueprint $table) {
            $table->id();

            // What the user chose in the dropdown BEFORE uploading (§J).
            $table->string('upload_type', 40)->index();
            $table->string('marketplace', 20)->nullable();
            $table->string('channel', 30)->nullable();

            // The file itself.
            $table->string('original_filename');
            $table->string('stored_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // sha256 of the contents - lets us spot an accidental re-upload of the
            // identical file and warn, rather than silently reprocessing it.
            $table->string('content_hash', 64)->nullable()->index();

            // Lifecycle.
            $table->string('status', 20)->default('pending')->index();
            $table->text('rejection_reason')->nullable();

            // What the import produced.
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            // Packing-list lines whose PO is not ingested yet are stored, not dropped (§K).
            $table->unsignedInteger('rows_unmatched')->default(0);

            // Free-form detail: parsed banner values, per-row warnings, header mapping used.
            $table->json('summary')->nullable();
            $table->json('warnings')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_files');
    }
};
