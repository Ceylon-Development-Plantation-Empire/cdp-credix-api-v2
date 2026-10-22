<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legal documents prepared for a loan (loan agreement, acknowledgement …): which text was used,
 * the details the legal officer typed in (agreement date, witnesses, investment particulars …) and
 * how often the document was printed.
 *
 * The wording itself is not here: it is a template (templates.channel = document, slug = the
 * document type), chosen per product, category or globally, in each language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference_no', 20)->nullable()->unique()->comment('LEG-000123; set right after the insert');
            // Restrict: a legal document is evidence for the loan it was made for.
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->comment('The wording used; null while only a draft')
                ->constrained('templates')->restrictOnDelete();
            $table->string('document_type', 40)->comment('App\Enums\LegalDocumentType');
            $table->char('language', 2)->default('en')->comment('App\Enums\Language');
            $table->string('status', 10)->default('pending')->comment('pending = draft; created = ready to print');
            $table->json('details')->nullable()->comment('What the legal officer typed in (dates, witnesses, investment particulars)');
            $table->text('remarks')->nullable();
            $table->unsignedInteger('printed_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamp('prepared_at')->nullable()->comment('When it first became created');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // One document of a kind and language per loan.
            $table->unique(['loan_id', 'document_type', 'language']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
