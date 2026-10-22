<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Legal\LegalTemplateRequest;
use App\Http\Resources\Legal\LegalTemplateResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Template;
use App\Services\Legal\LegalDocumentService;
use App\Traits\ActivityLogTrait;
use App\Traits\DeletesRecords;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The wording of legal documents: templates of the document channel, one per document type and
 * language, global or for a product or category. Written by the legal department, never deleted
 * (a document refers to the wording it was prepared with); switch one off instead.
 */
class LegalTemplateController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, DeletesRecords, HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected LegalDocumentService $legal,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Legal Document Index', only: ['index', 'activeList', 'show']),
            new Middleware('permission:Legal Template Manage', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = $this->documents()->with(['product', 'category'])->orderBy('slug')->orderBy('language');

            if ($request->filled('document_type')) {
                $query->where('slug', $request->document_type);
            }

            if ($request->filled('language')) {
                $query->where('language', $request->language);
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Legal templates retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), LegalTemplateResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve legal templates', $th);
        }
    }

    /**
     * The wording a product can use now: its own, its category's, then the global one (one per type and language).
     */
    public function activeList(Request $request)
    {
        try {
            $product = $request->filled('product_slug') ? Product::with('category.parent')->where('slug', $request->product_slug)->first() : null;
            $templates = $this->legal->applicable($product)->load(['product', 'category']);

            return response()->json([
                'status' => 'success',
                'message' => 'Legal templates retrieved successfully',
                'data' => LegalTemplateResource::collection($templates)->resolve(),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve legal templates', $th);
        }
    }

    public function show(int $id)
    {
        try {
            $template = $this->documents()->with(['product', 'category'])->find($id);

            return $template
                ? response()->json(['status' => 'success', 'message' => 'Legal template retrieved successfully', 'data' => new LegalTemplateResource($template)], 200)
                : $this->notFound('Legal template not found');
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve the legal template', $th);
        }
    }

    public function store(LegalTemplateRequest $request)
    {
        try {
            $data = $request->validated();

            $productId = isset($data['product_slug']) ? Product::where('slug', $data['product_slug'])->value('id') : null;
            $categoryId = isset($data['category_slug']) ? ProductCategory::where('slug', $data['category_slug'])->value('id') : null;

            // The database index cannot see two global templates as equal (NULLs differ), so check here.
            $exists = $this->documents()->where('slug', $data['document_type'])->where('language', $data['language'])
                ->where('product_id', $productId)->where('category_id', $categoryId)->exists();

            if ($exists) {
                return $this->duplicate();
            }

            $template = new Template([
                'slug' => $data['document_type'],
                'channel' => NotificationChannel::Document,
                'language' => $data['language'],
                'product_id' => $productId,
                'category_id' => $categoryId,
                'subject' => $data['title'] ?? null,
                'body' => $data['content'],
                'is_active' => $data['is_active'] ?? true,
                'updated_by' => $request->user()->id,
            ]);
            $template->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Legal template created successfully',
                'data' => new LegalTemplateResource($template->load(['product', 'category'])),
            ], 201);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate();
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create the legal template', $th);
        }
    }

    protected function duplicate()
    {
        return response()->json(['status' => 'error', 'code' => 'LEGAL_TEMPLATE_EXISTS', 'message' => 'There is already a template of this type and language for this product or category.'], 409);
    }

    public function update(LegalTemplateRequest $request, int $id)
    {
        try {
            $template = $this->documents()->find($id);

            if (! $template) {
                return $this->notFound('Legal template not found');
            }

            $data = $request->validated();
            $template->fill([
                'subject' => array_key_exists('title', $data) ? $data['title'] : $template->subject,
                'body' => $data['content'] ?? $template->body,
                'is_active' => $data['is_active'] ?? $template->is_active,
                'updated_by' => $request->user()->id,
            ])->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Legal template updated successfully',
                'data' => new LegalTemplateResource($template->load(['product', 'category'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update the legal template', $th);
        }
    }

    protected function documents()
    {
        return Template::query()->where('channel', NotificationChannel::Document->value);
    }

    public function destroy(int $id)
    {
        try {
            return $this->destroyRecord($this->documents()->find($id), 'legal template', 'LegalTemplate');
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete legal template', $th);
        }
    }
}
