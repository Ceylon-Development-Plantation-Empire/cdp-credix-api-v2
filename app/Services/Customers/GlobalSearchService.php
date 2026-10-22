<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\IdType;
use App\Enums\PartyRole;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanParty;
use App\Models\User;
use App\Support\Mask;
use Illuminate\Support\Collection;

/**
 * Look one person up across every module at once, from an identification document: whether they
 * are an employee, a customer, a guarantor or several of those, and every loan they touch,
 * saying for each whether they borrowed it, shared it, or stood surety for someone else's.
 *
 * Read-only, and branch-scoped like the rest of the API: customers and loans of other branches
 * are not shown (use GET /customers/lookup to learn where such a customer is registered).
 */
class GlobalSearchService
{
    /** What the screens offer, in the wording they display. */
    public const ID_TYPES = ['NIC', 'Passport', 'Driving License'];

    /** How a person is attached to a loan (the wording the screens already use). */
    public const ROLE_BORROWER = 'customer';

    public const ROLE_CO_BORROWER = 'joint_borrower';

    public const ROLE_GROUP_MEMBER = 'group_member';

    public const ROLE_GUARANTOR = 'guarantor';

    /**
     * Accepts "NIC", "nic", "Passport", "Driving License", "driving_licence" …
     */
    public static function idTypeFrom(string $given): ?IdType
    {
        return match (strtolower(preg_replace('/[\s_-]+/', '', trim($given)))) {
            'nic' => IdType::Nic,
            'passport' => IdType::Passport,
            'drivinglicense', 'drivinglicence' => IdType::DrivingLicence,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function search(IdType $type, string $idNumber): array
    {
        $normalized = $type->normalize($idNumber);

        $customers = $normalized === null ? collect() : Customer::query()
            ->where('id_type', $type->value)->where('id_number_hash', Customer::idNumberHash($normalized))
            ->with('branch:id,name')->get();

        $employees = $this->employees($idNumber, $normalized);

        $parties = $customers->isEmpty() ? collect() : LoanParty::query()
            ->whereIn('customer_id', $customers->pluck('id'))->whereNull('released_at')
            ->with(['loan' => fn ($q) => $q->with(['customer:id,customer_no,full_name', 'product:id,name', 'branch:id,name', 'borrowerGroup:id,group_no,name'])])
            ->get()
            ->filter(fn (LoanParty $party) => $party->loan !== null);

        $guarantorParties = $parties->filter(fn (LoanParty $party) => $party->role === PartyRole::Guarantor);

        $loans = $parties->map(fn (LoanParty $party) => $this->loanEntry($party))
            ->sortByDesc(fn (array $loan) => $loan['applied_at'] ?? '')->values();

        return [
            'identification' => ['id_type' => $type->value, 'id_number' => Mask::idNumber($normalized ?? $idNumber)],
            'found' => $employees->isNotEmpty() || $customers->isNotEmpty(),
            'person' => $this->identity($employees, $customers, $guarantorParties),
            'profiles' => [
                'employee' => $employees->map(fn (Employee $employee) => $this->employeeProfile($employee))->values(),
                'customer' => $customers->map(fn (Customer $customer) => $this->customerProfile($customer))->values(),
                'guarantor' => $guarantorParties->map(fn (LoanParty $party) => $this->guarantorProfile($party))->values(),
            ],
            'loans' => $loans,
            'summary' => $this->summary($employees, $customers, $guarantorParties, $loans),
        ];
    }

    /**
     * @return Collection<int, Employee>
     */
    protected function employees(string $idNumber, ?string $normalized): Collection
    {
        // Employees are indexed on the number as written (no spaces, upper case); try the NIC's other format too.
        $hashes = collect([$idNumber, $normalized])->filter()->map(fn (string $value) => Employee::idNumberHash($value))->unique()->all();

        return Employee::query()->whereIn('id_number_hash', $hashes)
            ->with(['branch:id,name', 'designation:id,name', 'department:id,name', 'user:id,employee_id,username,user_type,is_active,can_login'])
            ->get();
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, LoanParty>  $guarantorParties
     * @return array<string, mixed>|null
     */
    protected function identity(Collection $employees, Collection $customers, Collection $guarantorParties): ?array
    {
        $employee = $employees->first();
        $customer = $customers->first();

        if (! $employee && ! $customer) {
            return null;
        }

        return [
            'full_name' => $employee?->full_name ?? $customer?->full_name,
            'date_of_birth' => ($employee?->date_of_birth ?? $customer?->date_of_birth)?->toDateString(),
            'phone' => $employee?->phone_primary ?? $customer?->phone_primary,
            'email' => $employee?->email ?? $customer?->email,
            'known_as' => collect([
                $employees->isNotEmpty() ? 'employee' : null,
                $customers->isNotEmpty() ? 'customer' : null,
                $guarantorParties->isNotEmpty() ? 'guarantor' : null,
            ])->filter()->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function employeeProfile(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'phone' => $employee->phone_primary ?? $employee->phone,
            'branch' => $employee->branch?->name,
            'department' => $employee->department?->name,
            'designation' => $employee->designation?->name,
            'is_active' => (bool) $employee->is_active,
            'user_account' => $this->userAccount($employee->user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerProfile(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'public_id' => $customer->public_id,
            'customer_code' => $customer->customer_no,
            'full_name' => $customer->full_name,
            'email' => $customer->email,
            'phone' => $customer->phone_primary,
            'branch' => $customer->branch?->name,
            'credit_score' => $customer->credit_score,
            'is_active' => (bool) $customer->is_active,
            'user_account' => null,
        ];
    }

    /**
     * A guarantor is a customer standing surety on a loan: the profile says whose loan.
     *
     * @return array<string, mixed>
     */
    protected function guarantorProfile(LoanParty $party): array
    {
        $customer = $party->customer;
        $borrower = $party->loan->customer;

        return [
            'id' => $party->id,
            'full_name' => $customer?->full_name,
            'phone' => $customer?->phone_primary,
            'employment_status' => $customer?->employment_status?->value,
            'occupation' => $customer?->occupation,
            'employer_name' => $customer?->employer_name,
            'guarantees_for' => $borrower ? [
                'customer_id' => $borrower->id,
                'customer_code' => $borrower->customer_no,
                'full_name' => $borrower->full_name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function userAccount(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'username' => $user->username,
            'user_type' => $user->user_type,
            'is_active' => (bool) $user->is_active,
            'can_login' => (bool) $user->can_login,
        ] : null;
    }

    /**
     * One loan as it appears in the consolidated view.
     *
     * @return array<string, mixed>
     */
    protected function loanEntry(LoanParty $party): array
    {
        /** @var Loan $loan */
        $loan = $party->loan;
        $borrower = $loan->customer;

        return [
            'loan_application_id' => $loan->id,
            'public_id' => $loan->public_id,
            'application_no' => $loan->application_no,
            'loan_no' => $loan->loan_no,
            'role' => match ($party->role) {
                PartyRole::Borrower => self::ROLE_BORROWER,
                PartyRole::CoBorrower => self::ROLE_CO_BORROWER,
                PartyRole::GroupMember => self::ROLE_GROUP_MEMBER,
                PartyRole::Guarantor => self::ROLE_GUARANTOR,
            },
            'is_group_loan' => $loan->borrower_group_id !== null,
            'status' => $loan->status->value,
            'status_label' => ucwords(str_replace('_', ' ', $loan->status->value)),
            'is_active' => ! $loan->status->isClosed(),
            'loan_product' => $loan->product?->name,
            'branch' => $loan->branch?->name,
            // Named so a guarantor's row says whose loan they are standing on.
            'borrower' => $borrower ? [
                'customer_id' => $borrower->id,
                'customer_code' => $borrower->customer_no,
                'full_name' => $borrower->full_name,
            ] : ($loan->borrowerGroup ? ['full_name' => $loan->borrowerGroup->name, 'customer_code' => $loan->borrowerGroup->group_no] : null),
            'requested_amount' => (string) $loan->requested_amount,
            'approved_amount' => $loan->approved_amount !== null ? (string) $loan->approved_amount : null,
            'outstanding_balance' => (string) $loan->outstanding_principal,
            'term_months' => $loan->tenure,
            'interest_rate' => (string) $loan->rate,
            'applied_at' => $loan->created_at?->toApiString(),
            'approved_at' => $loan->approved_at?->toApiString(),
            'disbursed_at' => $loan->disbursed_at?->toApiString(),
            'guarantor_type' => null,
            'guarantor_status' => $party->role === PartyRole::Guarantor ? ($party->released_at ? 'released' : 'active') : null,
        ];
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, LoanParty>  $guarantorParties
     * @param  Collection<int, array<string, mixed>>  $loans
     * @return array<string, mixed>
     */
    protected function summary(Collection $employees, Collection $customers, Collection $guarantorParties, Collection $loans): array
    {
        $byRole = $loans->groupBy('role')->map->count();

        return [
            'is_employee' => $employees->isNotEmpty(),
            'is_customer' => $customers->isNotEmpty(),
            'is_guarantor' => $guarantorParties->isNotEmpty(),
            'total_loans' => $loans->count(),
            'loans_as_borrower' => $byRole[self::ROLE_BORROWER] ?? 0,
            'loans_as_co_borrower' => $byRole[self::ROLE_CO_BORROWER] ?? 0,
            'loans_as_group_member' => $byRole[self::ROLE_GROUP_MEMBER] ?? 0,
            'loans_as_guarantor' => $byRole[self::ROLE_GUARANTOR] ?? 0,
        ];
    }
}
