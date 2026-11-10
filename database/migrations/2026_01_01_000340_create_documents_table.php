<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every uploaded file, attached to a customer, a loan or a collateral item.
 *
 * SECURITY: files live on the private disk (storage/app/private), stored by the senior's
 * FileUploadTrait under a random name; there is no public URL. original_name is for display only
 * and never used to build a path. The checksum proves later that the stored file is the one that
 * was uploaded and reviewed.
 *
 * Soft deletes: only pending or rejected documents can be removed (the file is kept for audit);
 * a verified document is evidence and stays.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('documentable_type', 60)->comment('Morph alias: customer, loan, collateral');
            $table->unsignedBigInteger('documentable_id');
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('original_name', 200);
            $table->string('path')->unique()->comment('Private-disk path; never returned by the API');
            $table->string('mime_type', 100)->comment('Detected from the content, not the file name');
            $table->unsignedInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->string('status', 20)->default('pending')->comment('App\Enums\DocumentStatus');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The checklist reads an owner's documents by type.
            $table->index(['documentable_type', 'documentable_id', 'document_type_id'], 'documents_owner_type_index');
            $table->index('status');
        });

        CheckConstraint::add('documents', [
            'documents_validity_check' => 'expires_on IS NULL OR issued_on IS NULL OR expires_on >= issued_on',
        ]);
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
