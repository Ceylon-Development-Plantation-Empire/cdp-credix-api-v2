<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kinds of document the business accepts (NIC copy, salary slip, deed …), with their file rules.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 150);
            $table->string('target', 20)->index()->comment('App\Enums\DocumentTarget: person, loan, collateral');
            // A salary slip or bank statement older than this is not accepted. Null = never expires.
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->string('allowed_mimes', 100)->default('pdf,jpg,jpeg,png')->comment('Comma-separated extensions; the content type is checked too');
            $table->unsignedInteger('max_size_kb')->default(10240);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
