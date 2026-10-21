<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\BankAccountRequest;
use App\Http\Resources\Customers\BankAccountResource;
use App\Models\Customer;
use App\Services\Customers\BankAccountService;
use App\Traits\HandlesApiErrors;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CustomerBankAccountController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected BankAccountService $accounts,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Customer Index', only: ['index']),
            new Middleware('permission:Customer Bank Account Manage', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Bank accounts retrieved successfully',
                'data' => BankAccountResource::collection($customer->bankAccounts()->orderByDesc('is_primary')->get()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve bank accounts', $th);
        }
    }

    public function store(BankAccountRequest $request, string $publicId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();

            if (! $customer) {
                return $this->notFound('Customer not found');
            }

            $account = $this->accounts->create($customer, $request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Bank account added successfully',
                'data' => new BankAccountResource($account),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to add bank account', $th);
        }
    }

    public function update(BankAccountRequest $request, string $publicId, string $accountId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();
            $account = $customer?->bankAccounts()->where('public_id', $accountId)->first();

            if (! $account) {
                return $this->notFound('Bank account not found');
            }

            $account = $this->accounts->update($account, $request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Bank account updated successfully',
                'data' => new BankAccountResource($account),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update bank account', $th);
        }
    }

    public function destroy(string $publicId, string $accountId)
    {
        try {
            $customer = Customer::where('public_id', $publicId)->first();
            $account = $customer?->bankAccounts()->where('public_id', $accountId)->first();

            if (! $account) {
                return $this->notFound('Bank account not found');
            }

            $this->accounts->delete($account);

            return response()->json([
                'status' => 'success',
                'message' => 'Bank account removed successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to remove bank account', $th);
        }
    }
}
