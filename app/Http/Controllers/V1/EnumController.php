<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\BorrowerType;
use App\Enums\ChargeBehaviour;
use App\Enums\ChargeCalculation;
use App\Enums\ChargeCollection;
use App\Enums\ChargeRecurrence;
use App\Enums\DocumentFormat;
use App\Enums\DocumentStatus;
use App\Enums\DocumentTarget;
use App\Enums\EmploymentStatus;
use App\Enums\FinanceBasis;
use App\Enums\GlAccountType;
use App\Enums\GlJournalSource;
use App\Enums\GlJournalStatus;
use App\Enums\IdType;
use App\Enums\Language;
use App\Enums\LegalDocumentType;
use App\Enums\LoanStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\PartyRole;
use App\Enums\PaymentChannel;
use App\Enums\PricingMethod;
use App\Enums\RecoveryStage;
use App\Enums\RecoveryStatus;
use App\Enums\RepaymentFrequency;
use App\Enums\RequirementRule;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * The fixed option lists the engine understands (payment channels, loan stages, charge behaviours ...), so a
 * screen builds its dropdowns from the server instead of copying the values into its own code.
 *
 * Business-editable lists (banks, relationships, income categories ...) are the lookups, not these.
 */
class EnumController extends Controller
{
    public function index(): JsonResponse
    {
        $lists = [
            'loan_status' => LoanStatus::class,
            'borrower_type' => BorrowerType::class,
            'finance_basis' => FinanceBasis::class,
            'pricing_method' => PricingMethod::class,
            'repayment_frequency' => RepaymentFrequency::class,
            'payment_channel' => PaymentChannel::class,
            'party_role' => PartyRole::class,
            'employment_status' => EmploymentStatus::class,
            'id_type' => IdType::class,
            'language' => Language::class,
            'document_status' => DocumentStatus::class,
            'document_format' => DocumentFormat::class,
            'document_target' => DocumentTarget::class,
            'requirement_rule' => RequirementRule::class,
            'charge_calculation' => ChargeCalculation::class,
            'charge_recurrence' => ChargeRecurrence::class,
            'charge_collection' => ChargeCollection::class,
            'legal_document_type' => LegalDocumentType::class,
            'approval_status' => ApprovalStatus::class,
            'recovery_stage' => RecoveryStage::class,
            'recovery_status' => RecoveryStatus::class,
            'gl_account_type' => GlAccountType::class,
            'gl_journal_source' => GlJournalSource::class,
            'gl_journal_status' => GlJournalStatus::class,
            'notification_channel' => NotificationChannel::class,
            'notification_status' => NotificationStatus::class,
        ];

        $data = array_map(fn (string $enum) => $this->options($enum), $lists);

        // A charge behaviour also says what it allows, so the fee-type form offers only valid choices.
        $data['charge_behaviour'] = array_map(fn (ChargeBehaviour $b) => [
            'value' => $b->value,
            'label' => Str::headline($b->value),
            'event_driven' => $b->isEventDriven(),
            'calculations' => array_map(fn (ChargeCalculation $c) => $c->value, $b->permittedCalculations()),
            'recurrences' => array_map(fn (ChargeRecurrence $r) => $r->value, $b->permittedRecurrences()),
            'collections' => array_map(fn (ChargeCollection $c) => $c->value, $b->allowedCollections()),
        ], ChargeBehaviour::cases());

        // An approval request says which permission decides it and whether the decision must quote a reference.
        $data['approval_action'] = array_map(fn (ApprovalAction $a) => [
            'value' => $a->value,
            'label' => Str::headline($a->value),
            'permission' => $a->permission(),
            'requires_reference' => $a->requiresReference(),
        ], ApprovalAction::cases());

        // A message template is written for one event, and says which placeholders that event can fill.
        $data['notification_event'] = array_map(fn (NotificationEvent $e) => [
            'value' => $e->value,
            'label' => Str::headline(str_replace(['.', '-'], ' ', $e->value)),
            'placeholders' => $e->placeholders(),
        ], NotificationEvent::cases());

        return response()->json(['status' => 'success', 'message' => 'Option lists retrieved successfully', 'data' => $data], 200);
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @return list<array{value: string, label: string}>
     */
    protected function options(string $enum): array
    {
        return array_map(fn (\BackedEnum $case) => [
            'value' => (string) $case->value,
            'label' => method_exists($case, 'label') ? (string) $case->label() : Str::headline((string) $case->value),
        ], $enum::cases());
    }
}
