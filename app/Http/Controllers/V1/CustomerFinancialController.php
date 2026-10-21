<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerFinancialRequest;
use App\Http\Resources\Customers\CustomerFinancialResource;
use App\Models\Customer;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Income, expenses, assets and liabilities of a customer (the basis of the affordability check).
 * Every line is reached through its customer, so the customer's branch scope protects it too.
 */
class CustomerFinancialController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Customer Index', only: ['index']),
            new Middleware('permission:Customer Financial Manage', only: ['store', 'update', 'destroy']),
            new Middleware('permission:Customer Financial Verify', only: ['verify']),
        ];
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
