<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FinanceBasis;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCatalogSeeder extends Seeder
{
    /**
     * Seed the category tree and the products from the CDP Capital whiteboard (decision D-9).
     *
     * IMPORTANT: products are created as DRAFTS with placeholder rates and limits. They cannot be
     * sold until the business enters the real terms and activates them (Islamic ones through
     * Shariah approval). Nothing here should be read as CDP's actual pricing.
     */
    public function run(): void
    {
        $general = $this->category('general', 'General', FinanceBasis::Conventional, null, 1);
        $islamic = $this->category('islamic', 'Islamic', FinanceBasis::Islamic, null, 2);

        $standard = $this->category('standard-borrowing', 'Standard Borrowing', FinanceBasis::Conventional, $general, 1);
        $development = $this->category('development-fund', 'Development Fund', FinanceBasis::Conventional, $general, 2);
        $islamicStandard = $this->category('islamic-standard-borrowing', 'Standard Borrowing', FinanceBasis::Islamic, $islamic, 1);
        $islamicDev = $this->category('islamic-development-fund', 'Development Fund', FinanceBasis::Islamic, $islamic, 2);

        $individual = ['allows_individual' => true, 'allows_joint' => true, 'allows_group' => false];
        $group = ['allows_individual' => false, 'allows_joint' => false, 'allows_group' => true, 'min_group_members' => 3, 'max_group_members' => 30];

        $products = [
            ['education-loan', 'Education Loan', $standard, 'reducing_balance', 'monthly', $individual + ['min_guarantors' => 1, 'max_guarantors' => 2]],
            ['sme-loan', 'SME Loan', $standard, 'reducing_balance', 'monthly', $individual + ['min_guarantors' => 1, 'max_guarantors' => 2]],
            ['vehicle-loan', 'Vehicle Loan', $standard, 'reducing_balance', 'monthly', $individual + ['requires_collateral' => true]],
            ['vehicle-lease', 'Vehicle Lease', $standard, 'lease', 'monthly', $individual + ['requires_collateral' => true]],
            ['speed-draft', 'Speed Draft', $standard, 'reducing_balance', 'monthly', $individual + ['requires_collateral' => true]],
            ['home-loan', 'Home Loan', $standard, 'reducing_balance', 'monthly', $individual + ['requires_collateral' => true]],
            ['gold-pawning', 'Gold Pawning', $standard, 'gold_pawn', 'bullet', ['allows_individual' => true, 'requires_collateral' => true]],
            ['festival-loan', 'Festival Loan', $standard, 'flat', 'monthly', $individual + ['min_guarantors' => 1, 'max_guarantors' => 2]],
            ['womens-empowerment-micro-loan', "Women's Empowerment Micro Loan", $development, 'flat', 'weekly', $group],
            ['community-development-fund', 'Community Development Fund', $development, 'flat', 'monthly', $group],
            ['murabaha-asset-finance', 'Murabaha Asset Finance', $islamicStandard, 'murabaha', 'monthly', $individual + ['requires_collateral' => true]],
            ['ijara-vehicle-leasing', 'Ijara Vehicle Leasing', $islamicStandard, 'ijarah', 'monthly', $individual + ['requires_collateral' => true]],
            ['rahn-islamic-gold', 'Rahn Islamic Gold Pawning', $islamicStandard, 'rahn', 'bullet', ['allows_individual' => true, 'requires_collateral' => true]],
            ['qard-hasan-micro-finance', 'Qard Hasan Micro Finance', $islamicDev, 'qard_hasan', 'monthly', $group],
        ];

        foreach ($products as [$slug, $name, $category, $method, $frequency, $extra]) {
            $bullet = $frequency === 'bullet';

            Product::firstOrCreate(['slug' => $slug], array_merge([
                'name' => $name,
                'description' => 'Placeholder terms – review rates, limits, charges and documents before activating.',
                'category_id' => $category->id,
                'pricing_method' => $method,
                'default_rate' => '0',
                'min_rate' => '0',
                'max_rate' => '0',
                'min_amount' => '10000',
                'max_amount' => '1000000',
                'min_tenure' => $bullet ? 1 : 6,
                'max_tenure' => $bullet ? 12 : 60,
                'repayment_frequency' => $frequency,
            ], $extra));
        }
    }

    protected function category(string $slug, string $name, FinanceBasis $basis, ?ProductCategory $parent, int $order): ProductCategory
    {
        return ProductCategory::firstOrCreate(['slug' => $slug], [
            'name' => $name,
            'finance_basis' => $basis,
            'parent_id' => $parent?->id,
            'sort_order' => $order,
        ]);
    }
}
