<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every uploaded file, attached to a customer, a loan, a collateral or an approval request.
     *
     * Files live on the private disk (storage/app/private), never in public/, under a random
     * name. original_name is for display only and is never used to build a path. The checksum
     * proves later that the stored file is the one that was uploaded and reviewed.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->morphs('documentable');
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('original_name');
            $table->string('path')->unique();
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->string('status', 20)->default('pending')->index(); // App\Enums\DocumentStatus
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['documentable_type', 'documentable_id', 'document_type_id'], 'documents_owner_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
