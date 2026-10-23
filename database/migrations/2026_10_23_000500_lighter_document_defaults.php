<?php

declare(strict_types=1);

use App\Enums\LookupType;
use App\Support\Lookups\LookupRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Only the necessary documents are mandatory (owner decision 23 Oct 2026): identity proof and employment proof of
 * the borrower, identity proof of the guarantor, and one title document per asset. The salary slips and the bank
 * statements are one PDF each. Everything else stays available but optional, and each document keeps its own
 * mandatory tick for the business to change.
 *
 * Only rows that still equal the old defaults are changed, so anything head office edited is left alone.
 */
return new class extends Migration
{
    private const BORROWING = ['borrower', 'co_borrower', 'group_member'];

    public function up(): void
    {
        $typeId = fn (string $slug) => DB::table('document_types')->where('slug', $slug)->value('id');
        $global = fn () => DB::table('document_requirements')->whereNull('product_id');

        // Residency letters and the salary confirmation letter: optional for a borrower.
        if ($id = $typeId('gn-confirmation-letter')) {
            $global()->whereIn('party_role', self::BORROWING)->where('document_type_id', $id)->where('is_mandatory', true)->update(['is_mandatory' => false]);
        }

        if ($id = $typeId('ds-confirmation-letter')) {
            $global()->whereIn('party_role', self::BORROWING)->where('document_type_id', $id)->where('is_mandatory', true)->update(['is_mandatory' => false]);
        }

        if ($id = $typeId('salary-confirmation-letter')) {
            $global()->whereIn('party_role', self::BORROWING)->where('document_type_id', $id)->where('is_mandatory', true)->update(['is_mandatory' => false]);
        }

        // Three salary slips and six bank statements become one document each (one PDF).
        foreach (['salary-slip' => 3, 'bank-statement' => 6] as $slug => $old) {
            if ($id = $typeId($slug)) {
                $global()->where('document_type_id', $id)->where('min_count', $old)->update(['min_count' => 1]);
            }
        }

        DB::table('document_types')->where('slug', 'salary-slip')->where('name', 'Salary slip')->update(['name' => 'Salary slips (last 3 months, one PDF)']);
        DB::table('document_types')->where('slug', 'bank-statement')->where('name', 'Bank statement')->update(['name' => 'Bank statements (last 6 months, one PDF)']);

        // A guarantor needs only the identity proof.
        $global()->where('party_role', 'guarantor')->where('requirement_group', 'employment-proof')->where('is_mandatory', true)->update(['is_mandatory' => false]);

        // One title document per asset: the valuation report is optional.
        foreach (['property_mortgage' => ['property-deed', 'valuation-report'], 'vehicle' => ['vehicle-cr', 'valuation-report']] as $code => $old) {
            $row = DB::table('lookups')->where('type', LookupType::CollateralType->value)->where('code', $code)->first();
            $meta = $row ? json_decode((string) $row->meta, true) : null;

            if (is_array($meta) && ($meta['documents'] ?? null) === $old && empty($meta['optional_documents'])) {
                $meta['documents'] = [$old[0]];
                $meta['optional_documents'] = [$old[1]];
                DB::table('lookups')->where('id', $row->id)->update(['meta' => json_encode($meta)]);
            }
        }

        app(LookupRegistry::class)->forget(LookupType::CollateralType);
    }

    public function down(): void
    {
        // The earlier, stricter defaults are not restored: tick the documents as mandatory again in Document Setup.
    }
};
