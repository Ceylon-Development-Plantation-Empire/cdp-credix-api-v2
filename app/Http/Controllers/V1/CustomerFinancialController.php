<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerFinancialRequest;
use App\Http\Resources\Customers\CustomerFinancialListResource;
use App\Http\Resources\Customers\CustomerFinancialResource;
use App\Enums\FinancialKind;
use App\Enums\LookupType;
use App\Models\Customer;
use App\Models\CustomerFinancial;
use App\Support\Lookups\LookupRegistry;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Income, expenses, assets and liabilities of a customer (the basis of the affordability check).
 * Every line is reached through its customer, so the customer's branch scope protects it too.
 */
class CustomerFinancialController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors, PaginatesResources;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Customer Financial Index', only: ['index', 'all', 'show']),
            new Middleware('permission:Customer Financial Create', only: ['store']),
            new Middleware('permission:Customer Financial Update', only: ['update']),
            new Middleware('permission:Customer Financial Delete', only: ['destroy']),
            new Middleware('permission:Customer Financial Verify', only: ['verify']),
        ];
    }

    /**
     * Lines across customers (the branch scope still applies): ?kind=asset&category=vehicle gives every asset
     * of that category, for the fixed and moving asset screens.
     */
    public function all(Request $request)
    {
        try {
            $query = CustomerFinancial::query()->with('customer')->latest('id');

            $query->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->kind));
            $query->when($request->filled('is_verified'), fn ($q) => $q->where('is_verified', $request->boolean('is_verified')));
            $query->when($request->filled('customer_public_id'), fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('public_id', $request->customer_public_id)));
            $query->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('description', 'like', "%{$request->search}%")
                ->orWhere('institution_name', 'like', "%{$request->search}%")
                ->orWhere('reference_no', 'like', "%{$request->search}%")
                ->orWhereHas('customer', fn ($c) => $c->search((string) $request->search))));

            if ($request->filled('category')) {
                $query->whereIn('category_id', $this->categoryIds((string) $request->category, $request->input('kind')));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Customer financials retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), CustomerFinancialListResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve customer financials', $th);
        }
    }

    /**
     * The lookup ids a category code has: each kind keeps its own list, so a code can exist in more than one.
     *
     * @return list<int>
     */
    protected function categoryIds(string $code, ?string $kind): array
    {
        $kinds = $kind !== null && FinancialKind::tryFrom($kind) ? [FinancialKind::from($kind)] : FinancialKind::cases();

        return collect($kinds)
            ->flatMap(fn (FinancialKind $k) => app(LookupRegistry::class)->values(LookupType::forFinancialKind($k)))
            ->where('code', $code)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function index(Request $request, string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $items = $customer->financials()
                ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->kind))
                ->orderBy('kind')->orderBy('id')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Customer financials retrieved successfully',
                'data' => CustomerFinancialResource::collection($items),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve customer financials', $th);
        }
    }

    public function store(CustomerFinancialRequest $request, string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $item = $customer->financials()->create($request->validated());

            $this->logActivity('CREATE', 'CustomerFinancial', "Added {$item->kind->value} ({$item->category}) for {$customer->customer_no}");

            return response()->json([
                'status' => 'success',
                'message' => 'Financial item added successfully',
                'data' => new CustomerFinancialResource($item),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to add financial item', $th);
        }
    }

    /**
     * Changing a verified line clears its verification: the new figures have not been checked.
     */
    public function update(CustomerFinancialRequest $request, string $publicId, string $id)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();
            $item = $customer?->financials()->find($id);

            if (! $item) {
                return $this->notFound('Financial item not found');
            }

            $item->fill($request->validated());

            if ($item->isDirty()) {
                $item->forceFill(['is_verified' => false, 'verified_by' => null, 'verified_at' => null]);
            }

            $item->save();

            $this->logActivity('UPDATE', 'CustomerFinancial', "Updated financial item #{$item->id} of {$customer->customer_no}");

            return response()->json([
                'status' => 'success',
                'message' => 'Financial item updated successfully',
                'data' => new CustomerFinancialResource($item),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update financial item', $th);
        }
    }

    public function show(string $publicId, string $id)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();
            $item = $customer?->financials()->find($id);

            if (! $item) {
                return $this->notFound('Financial item not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Financial item retrieved successfully',
                'data' => new CustomerFinancialResource($item),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve financial item', $th);
        }
    }

    public function destroy(string $publicId, string $id)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();
            $item = $customer?->financials()->find($id);

            if (! $item) {
                return $this->notFound('Financial item not found');
            }

            $item->delete();

            $this->logActivity('DELETE', 'CustomerFinancial', "Removed financial item #{$id} of {$customer->customer_no}");

            return response()->json([
                'status' => 'success',
                'message' => 'Financial item removed successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to remove financial item', $th);
        }
    }

    /**
     * The credit officer confirms a figure against evidence (salary slip, statement …).
     */
    public function verify(Request $request, string $publicId, string $id)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();
            $item = $customer?->financials()->find($id);

            if (! $item) {
                return $this->notFound('Financial item not found');
            }

            $item->forceFill(['is_verified' => true, 'verified_by' => $request->user()->id, 'verified_at' => now()])->save();

            $this->logActivity('VERIFY', 'CustomerFinancial', "Verified financial item #{$item->id} of {$customer->customer_no}");

            return response()->json([
                'status' => 'success',
                'message' => 'Financial item verified',
                'data' => new CustomerFinancialResource($item),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to verify financial item', $th);
        }
    }
}
