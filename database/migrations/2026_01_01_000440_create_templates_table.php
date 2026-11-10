<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every SMS / email / agreement text, per language. The most specific one wins: product →
 * category (and its parents) → global. Texts use {{placeholders}} from a fixed list per event
 * only, so a template edited by staff can never run code or read data it was not given.
 */
return new class extends Migration
{
    /**
     * Create the table.
     */
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->comment('App\Enums\NotificationEvent value, e.g. loan.approved');
            $table->string('channel', 20)->comment('App\Enums\NotificationChannel');
            $table->char('language', 2)->default('en')->comment('App\Enums\Language');
            // Cascade is safe: a product's own wording has no meaning without the product (sent messages keep their hash).
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->cascadeOnDelete();
            $table->string('subject', 150)->nullable();
            // TEXT: agreement and letter texts are long.
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['slug', 'channel', 'language', 'product_id', 'category_id'], 'templates_resolution_unique');
        });
    }

    /**
     * Drop the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
