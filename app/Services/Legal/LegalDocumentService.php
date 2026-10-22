<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Enums\Language;
use App\Enums\LegalDocumentType;
use App\Enums\LoanStatus;
use App\Enums\NotificationChannel;
use App\Exceptions\BusinessRuleException;
use App\Models\LegalDocument;
use App\Models\Loan;
use App\Models\Product;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Legal documents of a loan: prepared after the loan is approved, in the language of the wording
 * that exists for the product. A document that has been printed is evidence and is never deleted.
 */
class LegalDocumentService
{
    /**
     * Legal papers are prepared once the loan is approved and until it is closed.
     *
     * @var list<LoanStatus>
     */
    public const ELIGIBLE = [LoanStatus::Approved, LoanStatus::Accepted, LoanStatus::Active];

    /**
     * @param  array{document_type: string, language: string, status?: string, template_id?: int|null, details?: array<string, mixed>|null, remarks?: string|null}  $data
     */
    public function create(Loan $loan, array $data, User $by): LegalDocument
    {
        $this->assertEligible($loan);

        $type = LegalDocumentType::from($data['document_type']);
        $language = Language::from($data['language']);
        $status = $data['status'] ?? 'pending';
        $template = $this->templateFor($loan->product, $type, $language, $data['template_id'] ?? null, $status === 'created');

        try {
            $document = new LegalDocument([
                'loan_id' => $loan->id,
                'template_id' => $template?->id,
                'document_type' => $type,
                'language' => $language,
                'details' => $data['details'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $by->id,
                'updated_by' => $by->id,
            ]);
            $document->forceFill(['status' => $status, 'prepared_at' => $status === 'created' ? now() : null])->save();
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('LEGAL_DOCUMENT_EXISTS', 'This loan already has this legal document in this language. Open it and update it.', 409);
        }

        return $document;
    }

    /**
     * @param  array{document_type?: string, language?: string, status?: string, template_id?: int|null, details?: array<string, mixed>|null, remarks?: string|null}  $data
     */
    public function update(LegalDocument $document, array $data, User $by): LegalDocument
    {
        $loan = $document->loan;
        $this->assertEligible($loan);

        $type = isset($data['document_type']) ? LegalDocumentType::from($data['document_type']) : $document->document_type;
        $language = isset($data['language']) ? Language::from($data['language']) : $document->language;
        $status = $data['status'] ?? $document->status;

        // A document that has been printed cannot go back to a draft.
        if ($status === 'pending' && $document->printed_count > 0) {
            throw new BusinessRuleException('LEGAL_DOCUMENT_PRINTED', 'A document that has been printed cannot go back to a draft.', 409);
        }

        $template = $this->templateFor($loan->product, $type, $language, $data['template_id'] ?? null, $status === 'created');

        try {
            $document->fill([
                'template_id' => $template?->id,
                'document_type' => $type,
                'language' => $language,
                'details' => array_key_exists('details', $data) ? $data['details'] : $document->details,
                'remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $document->remarks,
                'updated_by' => $by->id,
            ]);
            $document->forceFill([
                'status' => $status,
                'prepared_at' => $status === 'created' ? ($document->prepared_at ?? now()) : null,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('LEGAL_DOCUMENT_EXISTS', 'This loan already has this legal document in this language.', 409);
        }

        return $document;
    }

    public function recordPrint(LegalDocument $document): LegalDocument
    {
        if (! $document->isCreated()) {
            throw new BusinessRuleException('LEGAL_DOCUMENT_NOT_READY', 'Only a prepared document can be printed. Finish preparing it first.', 409);
        }

        $document->forceFill(['printed_count' => $document->printed_count + 1, 'last_printed_at' => now()])->save();

        return $document;
    }

    public function delete(LegalDocument $document): void
    {
        if ($document->printed_count > 0) {
            throw new BusinessRuleException('LEGAL_DOCUMENT_PRINTED', 'A document that has been printed is kept as evidence and cannot be deleted.', 409);
        }

        $document->delete();
    }

    /**
     * The wording for a product: its own, then its category's (and parents'), then the global one,
     * in exactly the language asked (a Tamil agreement never falls back to English text).
     * A template chosen by id must fit the type and the language.
     */
    public function templateFor(Product $product, LegalDocumentType $type, Language $language, ?int $templateId, bool $required): ?Template
    {
        if ($templateId !== null) {
            $template = Template::active()->where('channel', NotificationChannel::Document->value)->find($templateId);

            if (! $template || $template->slug !== $type->value || $template->language !== $language) {
                throw new BusinessRuleException('LEGAL_TEMPLATE_MISMATCH', 'That template is not an active template of this document type and language.', 422);
            }

            return $template;
        }

        $template = $this->applicable($product)
            ->first(fn (Template $t) => $t->slug === $type->value && $t->language === $language);

        if (! $template && $required) {
            $name = strtoupper($language->value);

            throw new BusinessRuleException('LEGAL_TEMPLATE_MISSING', "No approved {$name} legal template is registered for this loan product. Please contact the Legal department.", 422);
        }

        return $template;
    }

    /**
     * Active wording usable for a product, the most specific first (one per type and language).
     *
     * @return Collection<int, Template>
     */
    public function applicable(?Product $product): Collection
    {
        $categoryIds = [];
        $category = $product?->category;

        while ($category) {
            $categoryIds[] = $category->id;
            $category = $category->parent;
        }

        return Template::active()
            ->where('channel', NotificationChannel::Document->value)
            ->where(function ($q) use ($product, $categoryIds) {
                $q->where(fn ($g) => $g->whereNull('product_id')->whereNull('category_id'));

                if ($product) {
                    $q->orWhere('product_id', $product->id);
                }

                if ($categoryIds) {
                    $q->orWhereIn('category_id', $categoryIds);
                }
            })
            ->get()
            ->sortBy(fn (Template $t) => match (true) {
                $product && $t->product_id === $product->id => 0,
                $t->category_id !== null => 1 + (int) array_search($t->category_id, $categoryIds, true),
                default => 100,
            })
            ->unique(fn (Template $t) => $t->slug.'|'.$t->language->value)
            ->values();
    }

    protected function assertEligible(Loan $loan): void
    {
        if ($loan->isMemberLoan() || ! in_array($loan->status, self::ELIGIBLE, true)) {
            throw new BusinessRuleException('LEGAL_LOAN_NOT_ELIGIBLE', 'Legal documents are prepared for an approved, accepted or active loan (a group loan on its facility).', 409);
        }
    }
}
