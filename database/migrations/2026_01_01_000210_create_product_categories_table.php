<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The product category tree: General / Islamic at the root, then Standard Borrowing, Development
 * Fund and anything the business adds later, at any depth.
 *
 * DECISION (denormalised on purpose): finance_basis is set on the root and copied to every child,
 * so a product finds its basis with one join instead of walking up the tree. The category service
 * keeps the copies in step when a category is moved.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('finance_basis', 20)->index()->comment('App\Enums\FinanceBasis: conventional, islamic');
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
