<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\RejectDocumentRequest;
use App\Http\Resources\Documents\DocumentResource;
use App\Models\Collateral;
use App\Models\Document;
use App\Services\Documents\DocumentService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Actions on a single document, addressed by its public id.
 *
 * A document is only found when its owner (the customer, a loan …) is visible to the user, so
 * the owner's branch scope also protects the file: findAccessible() returns null otherwise.
 */
class DocumentController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected DocumentService $documents,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Document Index', only: ['download']),
            new Middleware('permission:Document Verify', only: ['verify', 'reject']),
            new Middleware('permission:Document Delete', only: ['destroy']),
        ];
    }

    public function download(string $publicId)
    {
        try {
            $document = $this->findAccessible($publicId);

            if (! $document) {
                return $this->notFound('Document not found');
            }

            return $this->documents->download($document);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to download document', $th);
        }
    }

    public function verify(Request $request, string $publicId)
    {
        try {
            $document = $this->findAccessible($publicId);

            if (! $document) {
                return $this->notFound('Document not found');
            }

            $document = $this->documents->verify($document, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Document verified',
                'data' => new DocumentResource($document->load(['documentType', 'uploader', 'verifier'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to verify document', $th);
        }
    }

    public function reject(RejectDocumentRequest $request, string $publicId)
    {
        try {
            $document = $this->findAccessible($publicId);

            if (! $document) {
                return $this->notFound('Document not found');
            }

            $document = $this->documents->reject($document, $request->user(), $request->validated('reason'));

            return response()->json([
                'status' => 'success',
                'message' => 'Document rejected',
                'data' => new DocumentResource($document->load(['documentType', 'uploader', 'verifier'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to reject document', $th);
        }
    }

    public function destroy(string $publicId)
    {
        try {
            $document = $this->findAccessible($publicId);

            if (! $document) {
                return $this->notFound('Document not found');
            }

            $this->documents->delete($document);

            return response()->json([
                'status' => 'success',
                'message' => 'Document deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete document', $th);
        }
    }

    protected function findAccessible(string $publicId): ?Document
    {
        $document = Document::with('documentType')->where('public_id', $publicId)->first();
        $owner = $document?->documentable;

        // documentable is loaded through the owner's model, so its branch scope applies here.
        // Collateral has no branch of its own: it is visible when its loan is.
        if ($owner instanceof Collateral) {
            $owner = $owner->loan;
        }

        return $owner ? $document : null;
    }
}
