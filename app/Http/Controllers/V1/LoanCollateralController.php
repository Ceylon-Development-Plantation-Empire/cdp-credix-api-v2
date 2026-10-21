<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Http\Requests\Loans\CollateralRequest;
use App\Http\Requests\Loans\PerfectCollateralRequest;
use App\Http\Resources\Documents\DocumentResource;
use App\Http\Resources\Loans\CollateralResource;
use App\Models\Collateral;
use App\Models\DocumentType;
use App\Models\Loan;
use App\Services\Documents\DocumentService;
use App\Services\Loans\CollateralService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Collateral is always reached through its loan, so the loan's branch scope protects it too.
 */
class LoanCollateralController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected CollateralService $collaterals,
        protected DocumentService $documents,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Loan Index', only: ['index']),
            new Middleware('permission:Loan Collateral Manage', only: ['store', 'destroy']),
            new Middleware('permission:Loan Collateral Verify', only: ['verify']),
            new Middleware('permission:Loan Collateral Perfect', only: ['perfect']),
            new Middleware('permission:Document Upload', only: ['uploadDocument']),
        ];
    }

    public function index(string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Collaterals retrieved successfully',
                'data' => CollateralResource::collection($loan->collaterals()->with('owner')->get()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve collaterals', $th);
        }
    }

    public function store(CollateralRequest $request, string $publicId)
    {
        try {
            $loan = Loan::where('public_id', $publicId)->first();

            if (! $loan) {
                return $this->notFound('Loan not found');
            }

            $collateral = $this->collaterals->add($loan, $request->validated(), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Collateral added successfully',
                'data' => new CollateralResource($collateral->load('owner')),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to add collateral', $th);
        }
    }

    public function verify(Request $request, string $publicId, string $collateralId)
    {
        try {
            $collateral = $this->find($publicId, $collateralId);

            if (! $collateral) {
                return $this->notFound('Collateral not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Collateral verified',
                'data' => new CollateralResource($this->collaterals->verify($collateral, $request->user())->load('owner')),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to verify collateral', $th);
        }
    }

    public function perfect(PerfectCollateralRequest $request, string $publicId, string $collateralId)
    {
        try {
            $collateral = $this->find($publicId, $collateralId);

            if (! $collateral) {
                return $this->notFound('Collateral not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Collateral perfected',
                'data' => new CollateralResource($this->collaterals->perfect($collateral, $request->validated(), $request->user())->load('owner')),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to perfect collateral', $th);
        }
    }

    public function destroy(Request $request, string $publicId, string $collateralId)
    {
        try {
            $collateral = $this->find($publicId, $collateralId);

            if (! $collateral) {
                return $this->notFound('Collateral not found');
            }

            $this->collaterals->remove($collateral, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Collateral removed successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to remove collateral', $th);
        }
    }

    /**
     * Deed, CR, valuation report or gold valuation slip of one collateral.
     */
    public function uploadDocument(UploadDocumentRequest $request, string $publicId, string $collateralId)
    {
        try {
            $collateral = $this->find($publicId, $collateralId);

            if (! $collateral) {
                return $this->notFound('Collateral not found');
            }

            $data = $request->validated();
            $document = $this->documents->upload($collateral, DocumentType::findBySlug($data['document_type_slug']), $request->file('file'), $data, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Document uploaded successfully',
                'data' => new DocumentResource($document->load(['documentType', 'uploader'])),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to upload document', $th);
        }
    }

    protected function find(string $publicId, string $collateralId): ?Collateral
    {
        $loan = Loan::where('public_id', $publicId)->first();

        return $loan?->collaterals()->where('public_id', $collateralId)->first();
    }
}
