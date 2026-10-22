<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\IdType;
use App\Enums\LookupType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CreateCustomerRequest;
use App\Http\Requests\Customers\CustomerIndexRequest;
use App\Http\Requests\Customers\CustomerLookupRequest;
use App\Http\Requests\Customers\EligibilityRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\Customers\CustomerListResource;
use App\Http\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Customers\CreditScoreService;
use App\Services\Customers\CustomerService;
use App\Services\Loans\EligibilityService;
use App\Support\Lookups\LookupRegistry;
use App\Traits\ActivityLogTrait;
use App\Traits\DeletesRecords;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Customers are always loaded through the Customer model, whose branch scope hides customers of
 * other branches: for a user without head-office access they return 404 on every endpoint.
 */
class CustomerController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, DeletesRecords, HandlesApiErrors, PaginatesResources;

    protected const DETAIL_RELATIONS = ['branch', 'recommendedBy', 'currentGroupMembership.group'];

    public function __construct(
        protected CustomerService $customers,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Customer Delete', only: ['destroy']),
            new Middleware('permission:Customer Index', only: ['index', 'show', 'lookup', 'eligibility', 'creditScore', 'bankOptions']),
            new Middleware('permission:Customer Create', only: ['store']),
            new Middleware('permission:Customer Update', only: ['update']),
            new Middleware('permission:Customer Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(CustomerIndexRequest $request)
    {
        try {
            // ?sort=-credit_score ranks customers by score, best first (the Credit Scores screen).
            // The sort value is whitelisted by CustomerIndexRequest.
            $query = Customer::with('branch');
            match ($request->validated('sort') ?? 'newest') {
                'name' => $query->orderBy('full_name'),
                'credit_score' => $query->orderByRaw('credit_score IS NULL')->orderBy('credit_score')->orderBy('id'),
                '-credit_score' => $query->orderByRaw('credit_score IS NULL')->orderByDesc('credit_score')->orderBy('id'),
                default => $query->latest('id'),
            };

            if ($request->filled('search')) {
                $query->search($request->search);
            }

            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->filled('kyc_status')) {
                $query->where('kyc_status', $request->kyc_status);
            }

            if ($request->filled('district')) {
                $query->where('district', $request->district);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Customers retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), CustomerListResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve customers', $th);
        }
    }

    /**
     * Bank names for the bank-account dropdown: the active values of the "bank" lookup list,
     * edited by administrators under Lookups.
     */
    public function bankOptions(LookupRegistry $lookups)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Bank options retrieved successfully',
            'data' => $lookups->selectableLabels(LookupType::Bank),
        ], 200);
    }

    /**
     * "Is this person already registered?" — checked across all branches before creating a customer.
     */
    public function lookup(CustomerLookupRequest $request)
    {
        try {
            $data = $request->validated();

            $match = $this->customers->lookup(IdType::from($data['id_type']), $data['id_number']);

            return response()->json([
                'status' => 'success',
                'message' => $match ? 'Customer is already registered' : 'No customer registered with this ID',
                'data' => ['exists' => $match !== null, 'customer' => $match],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to look up customer', $th);
        }
    }

    /**
     * Can this person borrow on a product, and how many guarantees do they already give?
     * Every problem is listed at once (?product_slug=sme-loan&tenure=24).
     */
    public function eligibility(EligibilityRequest $request, string $publicId, EligibilityService $eligibility)
    {
        try {
            $data = $request->validated();

            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $product = Product::findBySlug($data['product_slug']);
            $problems = $eligibility->borrowerProblems($customer, (int) ($data['tenure'] ?? $product->min_tenure), $product->repayment_frequency, true);

            return response()->json([
                'status' => 'success',
                'message' => 'Eligibility checked',
                'data' => [
                    'eligible' => $problems === [],
                    'problems' => $problems,
                    'open_guarantees' => $eligibility->openGuarantees($customer),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to check eligibility', $th);
        }
    }

    public function store(CreateCustomerRequest $request)
    {
        try {
            $data = $request->validated();
            $customer = $this->customers->create($data, $request->user());

            $this->logActivity('CREATE', 'Customer', "Registered customer {$customer->customer_no}", ['branch_id' => $customer->branch_id]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer registered successfully',
                'data' => new CustomerResource($customer->load(self::DETAIL_RELATIONS)),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to register customer', $th);
        }
    }

    public function show(string $publicId)
    {
        try {
            $customer = Customer::with(self::DETAIL_RELATIONS)->where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Customer retrieved successfully',
                'data' => new CustomerResource($customer),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve customer', $th);
        }
    }

    public function update(UpdateCustomerRequest $request, string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $data = $request->validated();
            $customer = $this->customers->update($customer, $data, $request->user());

            // Only the field names are logged, not the personal data itself.
            $this->logActivity('UPDATE', 'Customer', "Updated customer {$customer->customer_no}", ['fields' => array_keys($data)]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer updated successfully',
                'data' => new CustomerResource($customer->load(self::DETAIL_RELATIONS)),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update customer', $th);
        }
    }

    /**
     * Customers are never deleted (loans and documents refer to them); they are deactivated.
     */
    public function toggleStatus(string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $customer->update(['is_active' => ! $customer->is_active, 'updated_by' => auth('api')->id()]);

            $this->logActivity('TOGGLE_STATUS', 'Customer', "Customer {$customer->customer_no} is now ".($customer->is_active ? 'active' : 'inactive'));

            return response()->json([
                'status' => 'success',
                'message' => 'Customer status updated successfully',
                'data' => [
                    'public_id' => $customer->public_id,
                    'is_active' => $customer->is_active,
                ],
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update customer status', $th);
        }
    }

    public function creditScore(string $publicId, CreditScoreService $creditScores)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Credit score calculated',
                'data' => $creditScores->compute($customer),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to calculate credit score', $th);
        }
    }

    /**
     * A customer with any loan history (borrower, co-borrower, member or guarantor) stays; deactivate instead.
     */
    public function destroy(string $publicId)
    {
        try {
            return $this->destroyRecord(Customer::where('public_id', $publicId)->first(), 'customer', 'Customer', fn (Customer $customer) => match (true) {
                \App\Models\LoanParty::where('customer_id', $customer->id)->exists() => 'This customer is on a loan or application. Deactivate the customer instead.',
                \App\Models\Loan::where('customer_id', $customer->id)->exists() => 'This customer has loans. Deactivate the customer instead.',
                $customer->groupMemberships()->exists() => 'This customer belongs to (or was in) a borrower group. Deactivate the customer instead.',
                default => null,
            });
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete customer', $th);
        }
    }
}
