<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 150);
            $table->string('target', 20)->index(); // App\Enums\DocumentTarget
            // A salary slip or bank statement older than this is not accepted. Null = never expires.
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->string('allowed_mimes', 100)->default('pdf,jpg,jpeg,png');
            $table->unsignedInteger('max_size_kb')->default(10240);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
