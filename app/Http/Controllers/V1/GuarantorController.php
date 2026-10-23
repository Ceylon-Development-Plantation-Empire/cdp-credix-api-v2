<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\PartyRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Loans\GuarantorListResource;
use App\Models\Customer;
use App\Models\LoanParty;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Every guarantee across the loans the user's branch may see. A guarantor is not a record of its own: it is a
 * customer standing on a loan, so this reads the loan parties. Add or remove one on the loan itself.
 */
class GuarantorController extends Controller implements HasMiddleware
{
    use HandlesApiErrors, PaginatesResources;

    public static function middleware(): array
    {
        return [new Middleware('permission:Loan Index', only: ['index'])];
    }

    public function index(Request $request)
    {
        try {
            $query = LoanParty::query()
                ->where('role', PartyRole::Guarantor->value)
                ->whereHas('loan')
                ->with(['customer', 'loan.customer'])
                ->latest('id');

            if ($request->filled('search')) {
                $query->whereHas('customer', fn ($q) => $q->search((string) $request->search));
            }

            if ($request->filled('customer_public_id')) {
                $query->where('customer_id', Customer::where('public_id', (string) $request->customer_public_id)->value('id') ?? 0);
            }

            if ($request->filled('loan_status')) {
                $query->whereHas('loan', fn ($q) => $q->where('status', $request->loan_status));
            }

            // consented = the guarantor agreed; invoked = the guarantee was called on; released = let go.
            foreach (['consented' => 'consented_at', 'invoked' => 'invoked_at', 'released' => 'released_at'] as $filter => $column) {
                if ($request->filled($filter)) {
                    $request->boolean($filter) ? $query->whereNotNull($column) : $query->whereNull($column);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Guarantors retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), GuarantorListResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve guarantors', $th);
        }
    }
}
