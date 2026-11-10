<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LookupType;
use App\Models\Lookup;
use App\Support\Collateral\CollateralTypes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * System reference data: the starting values of every code list (App\Enums\LookupType).
 *
 * Idempotent: firstOrCreate on (type, code) adds missing values and never overwrites a label,
 * order or active flag someone has since changed. Safe to run on every deploy.
 */
class LookupSeeder extends Seeder
{
    /**
     * Licensed commercial and specialised banks in Sri Lanka (Central Bank list).
     */
    public const BANKS = [
        'Amana Bank', 'Bank of Ceylon', 'Bank of China', 'Cargills Bank', 'Citibank N.A.',
        'Commercial Bank of Ceylon', 'Deutsche Bank AG', 'DFCC Bank', 'Habib Bank', 'Hatton National Bank',
        'HDFC Bank', 'HSBC', 'Indian Bank', 'Indian Overseas Bank', 'MCB Bank', 'National Development Bank',
        'National Savings Bank', 'Nations Trust Bank', 'Pan Asia Banking Corporation', "People's Bank",
        'Pradeshiya Sanwardhana Bank', 'Public Bank Berhad', 'Sampath Bank', 'Sanasa Development Bank',
        'Seylan Bank', 'Standard Chartered Bank', 'State Bank of India', 'State Mortgage and Investment Bank',
        'Union Bank of Colombo',
    ];

    /**
     * Seed every list.
     */
    public function run(): void
    {
        // Codes the starter-kit API already accepts are kept exactly (including "Manager" / "Director").
        $this->list(LookupType::BranchType, ['main' => 'Main', 'city' => 'City', 'satellite' => 'Satellite', 'mobile' => 'Mobile'], system: true);
        $this->list(LookupType::EmployeeType, ['permanent' => 'Permanent', 'contract' => 'Contract', 'internship' => 'Internship', 'probation' => 'Probation'], system: true);
        $this->list(LookupType::DesignationLevel, [
            'entry' => ['Entry', ['weight' => 10]],
            'mid' => ['Mid', ['weight' => 20]],
            'senior' => ['Senior', ['weight' => 30]],
            'lead' => ['Lead', ['weight' => 40]],
            'executive' => ['Executive', ['weight' => 50]],
            'Manager' => ['Manager', ['weight' => 60]],
            'Director' => ['Director', ['weight' => 70]],
        ], system: true);

        $this->list(LookupType::IncomeCategory, $this->labels(['salary', 'allowance', 'business_income', 'rental_income', 'agriculture', 'remittance', 'pension', 'other']));
        $this->list(LookupType::ExpenseCategory, $this->labels(['household', 'rent', 'education', 'utilities', 'transport', 'medical', 'other']));
        $this->list(LookupType::AssetCategory, $this->labels(['land', 'house', 'vehicle', 'machinery', 'shares', 'savings', 'gold', 'other']));
        $this->list(LookupType::LiabilityCategory, $this->labels(['bank_loan', 'leasing', 'credit_card', 'microfinance', 'pawning', 'informal', 'other']));

        $this->list(LookupType::GuarantorRelationship, $this->labels(['family', 'spouse', 'relative', 'friend', 'employer', 'colleague', 'business_partner', 'other']));

        // Collateral types are data; the five the engine and the API started with are system values.
        $this->list(LookupType::CollateralType, array_map(fn (array $type) => [$type['label'], $type['meta']], CollateralTypes::SYSTEM), system: true);

        $banks = [];
        foreach (self::BANKS as $name) {
            $banks[Str::slug($name, '_')] = $name;
        }
        $this->list(LookupType::Bank, $banks);
    }

    /**
     * Create the missing values of one list, in the given order.
     *
     * @param  array<string, string|array{0: string, 1: array<string, mixed>}>  $values  code => label, or code => [label, meta]
     * @param  bool  $system  Values the starter API / code relies on: relabel only, never recode.
     */
    private function list(LookupType $type, array $values, bool $system = false): void
    {
        $order = 0;
        foreach ($values as $code => $value) {
            [$label, $meta] = is_array($value) ? $value : [$value, null];
            Lookup::firstOrCreate(
                ['type' => $type->value, 'code' => (string) $code],
                ['label' => $label, 'meta' => $meta, 'sort_order' => $order += 10, 'is_active' => true, 'is_system' => $system]
            );
        }
    }

    /**
     * Turn snake_case codes into code => "Sentence label".
     *
     * @param  list<string>  $codes
     * @return array<string, string>
     */
    private function labels(array $codes): array
    {
        $labels = [];
        foreach ($codes as $code) {
            $labels[$code] = Str::ucfirst(str_replace('_', ' ', $code));
        }

        return $labels;
    }
}
