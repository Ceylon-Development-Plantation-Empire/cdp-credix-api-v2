<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\Language;
use App\Enums\NotificationChannel;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\Template;

/**
 * Finds and fills message templates.
 *
 * Resolution, most specific first: the product's own template, then its category and the
 * category's parents (Development Fund → General), then the global one. In each place the
 * customer's language is preferred and English is the fallback, so a message is never missing
 * just because a translation has not been written yet.
 */
class TemplateService
{
    public function resolve(string $slug, NotificationChannel $channel, Language $language, ?Product $product = null): ?Template
    {
        $categoryIds = [];
        $category = $product?->category;

        while ($category) {
            $categoryIds[] = $category->id;
            $category = $category->parent;
        }

        $candidates = Template::active()
            ->where('slug', $slug)
            ->where('channel', $channel->value)
            ->whereIn('language', array_unique([$language->value, Language::English->value]))
            ->where(function ($q) use ($product, $categoryIds) {
                $q->whereNull('product_id')->whereNull('category_id');

                if ($product) {
                    $q->orWhere('product_id', $product->id);
                }

                if ($categoryIds) {
                    $q->orWhereIn('category_id', $categoryIds);
                }
            })
            ->get();

        // Lower rank wins: product (0) → nearest category (1 …) → global; then the customer's language.
        return $candidates->sortBy(function (Template $t) use ($product, $categoryIds, $language) {
            $place = match (true) {
                $product && $t->product_id === $product->id => 0,
                $t->category_id !== null => 1 + (int) array_search($t->category_id, $categoryIds, true),
                default => 100,
            };

            return $place * 2 + ($t->language === $language ? 0 : 1);
        })->first();
    }

    /**
     * Replace {{placeholders}} with prepared values. Anything that is not in the allowed list is
     * removed, never evaluated.
     *
     * @param  list<string>  $allowed
     * @param  array<string, scalar|null>  $values
     */
    public function render(string $text, array $allowed, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $m) use ($allowed, $values) {
            return in_array($m[1], $allowed, true) ? (string) ($values[$m[1]] ?? '') : '';
        }, $text);
    }

    /**
     * Templates may only use the placeholders their event offers. Checked when staff save one.
     *
     * @param  list<string>  $allowed
     */
    public function assertPlaceholders(string $text, array $allowed): void
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches);
        $unknown = array_values(array_diff(array_unique($matches[1]), $allowed));

        if ($unknown) {
            throw new BusinessRuleException('TEMPLATE_PLACEHOLDER_UNKNOWN', 'The template uses placeholders this message does not offer.', 422, [
                'unknown' => $unknown,
                'allowed' => $allowed,
            ]);
        }
    }
}
