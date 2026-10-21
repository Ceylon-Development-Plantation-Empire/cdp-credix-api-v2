<?php

namespace Database\Seeders;

use App\Enums\DocumentTarget;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentRequirementSeeder extends Seeder
{
    /**
     * Seed document types and the customer / guarantor document rules agreed with the business
     * (design section 7.4). They are only a starting point; staff can change them in the app.
     */
    public function run(): void
    {
        $types = [
            // Identity
            ['nic-copy', 'NIC copy', DocumentTarget::Person, null],
            ['passport-copy', 'Passport copy', DocumentTarget::Person, null],
            ['driving-licence-copy', 'Driving licence copy', DocumentTarget::Person, null],
            // Residency
            ['gn-confirmation-letter', 'Grama Niladhari (GN) division confirmation letter', DocumentTarget::Person, 180],
            ['ds-confirmation-letter', 'Divisional Secretariat (DS) division confirmation letter', DocumentTarget::Person, 180],
            // Employment
            ['salary-confirmation-letter', 'Salary confirmation letter', DocumentTarget::Person, 90],
            ['employment-letter', 'Employment letter', DocumentTarget::Person, 180],
            ['salary-slip', 'Salary slip', DocumentTarget::Person, 120],
            ['bank-statement', 'Bank statement', DocumentTarget::Person, 210],
            // Other
            ['billing-proof', 'Billing proof', DocumentTarget::Person, 90],
            ['customer-photo', 'Customer photograph', DocumentTarget::Person, null],
            ['other-supporting-document', 'Other supporting document', DocumentTarget::Person, null],
            // Collateral
            ['property-deed', 'Property deed', DocumentTarget::Collateral, null],
            ['valuation-report', 'Valuation report', DocumentTarget::Collateral, 365],
            ['vehicle-cr', 'Vehicle registration certificate (CR)', DocumentTarget::Collateral, null],
            ['gold-valuation-slip', 'Gold valuation slip', DocumentTarget::Collateral, null],
            // Loan
            ['loan-agreement', 'Signed loan agreement', DocumentTarget::Loan, null],
        ];

        foreach ($types as [$slug, $name, $target, $validityDays]) {
            DocumentType::firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'target' => $target,
                'validity_days' => $validityDays,
            ]);
        }

        $id = fn (string $slug) => DocumentType::where('slug', $slug)->value('id');

        // [party role, employment status, group, rule, document type, min count, mandatory]
        $rules = [];

        foreach (['borrower', 'co_borrower', 'group_member'] as $role) {
            // A. Identity proof – at least one of
            foreach (['nic-copy', 'passport-copy', 'driving-licence-copy'] as $doc) {
                $rules[] = [$role, null, 'identity-proof', 'any', $doc, 1, true];
            }

            // B. Address and residency – both
            foreach (['gn-confirmation-letter', 'ds-confirmation-letter'] as $doc) {
                $rules[] = [$role, null, 'residency-proof', 'all', $doc, 1, true];
            }

            // C. Employment
            $rules[] = [$role, 'employed', 'employment-proof', 'all', 'salary-confirmation-letter', 1, true];
            $rules[] = [$role, 'employed', 'employment-proof', 'all', 'employment-letter', 1, true];
            $rules[] = [$role, 'employed', 'employment-proof', 'all', 'salary-slip', 3, true];
            $rules[] = [$role, 'self_employed', 'employment-proof', 'all', 'bank-statement', 6, true];

            // D. Other documents that may be collected
            foreach (['billing-proof', 'customer-photo', 'other-supporting-document'] as $doc) {
                $rules[] = [$role, null, 'other-documents', 'any', $doc, 1, false];
            }
        }

        // Guarantor: identity and employment only (no residency letters)
        foreach (['nic-copy', 'passport-copy', 'driving-licence-copy'] as $doc) {
            $rules[] = ['guarantor', null, 'identity-proof', 'any', $doc, 1, true];
        }

        $rules[] = ['guarantor', 'employed', 'employment-proof', 'all', 'salary-confirmation-letter', 1, true];
        $rules[] = ['guarantor', 'employed', 'employment-proof', 'all', 'employment-letter', 1, true];
        $rules[] = ['guarantor', 'employed', 'employment-proof', 'all', 'salary-slip', 3, true];
        $rules[] = ['guarantor', 'self_employed', 'employment-proof', 'all', 'bank-statement', 6, true];

        foreach ($rules as [$role, $employment, $group, $rule, $doc, $minCount, $mandatory]) {
            DocumentRequirement::firstOrCreate([
                'product_id' => null,
                'party_role' => $role,
                'employment_status' => $employment,
                'requirement_group' => $group,
                'document_type_id' => $id($doc),
            ], [
                'rule' => $rule,
                'min_count' => $minCount,
                'is_mandatory' => $mandatory,
            ]);
        }
    }
}
