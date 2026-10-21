<?php

namespace App\Http\Controllers\V1;

use App\Enums\NotificationEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\TemplateRequest;
use App\Http\Resources\Notifications\NotificationLogResource;
use App\Http\Resources\Notifications\TemplateResource;
use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Template;
use App\Services\Notifications\TemplateService;
use App\Support\BranchAccess;
use App\Traits\ActivityLogTrait;
use App\Traits\HandlesApiErrors;
use App\Traits\PaginatesResources;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Message templates (SMS texts per event, language and product) and the log of what was sent.
 */
class TemplateController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, HandlesApiErrors, PaginatesResources;

    public function __construct(
        protected TemplateService $templates,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Template Index', only: ['index', 'show']),
            new Middleware('permission:Template Manage', only: ['store', 'update']),
            new Middleware('permission:Notification Log Index', only: ['logs']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = Template::with(['product', 'category'])->orderBy('slug')->orderBy('language');

            foreach (['slug', 'channel', 'language'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where($filter, $request->get($filter));
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Templates retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), TemplateResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve templates', $th);
        }
    }

    public function show(int $id)
    {
        try {
            $template = Template::with(['product', 'category'])->find($id);

            if (! $template) {
                return $this->notFound('Template not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Template retrieved successfully',
                'data' => new TemplateResource($template),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve template', $th);
        }
    }

    public function store(TemplateRequest $request)
    {
        try {
            $data = $request->validated();
            $this->templates->assertPlaceholders($data['body'], NotificationEvent::from($data['slug'])->placeholders());

            $productId = isset($data['product_slug']) ? Product::findBySlug($data['product_slug'])->id : null;
            $categoryId = isset($data['category_slug']) ? ProductCategory::findBySlug($data['category_slug'])->id : null;

            // The unique index does not catch two global templates (NULLs are distinct in MySQL), so check here.
            $exists = Template::where(['slug' => $data['slug'], 'channel' => $data['channel'], 'language' => $data['language']])
                ->where(fn ($q) => $productId ? $q->where('product_id', $productId) : $q->whereNull('product_id'))
                ->where(fn ($q) => $categoryId ? $q->where('category_id', $categoryId) : $q->whereNull('category_id'))
                ->exists();

            if ($exists) {
                return response()->json(['status' => 'error', 'code' => 'TEMPLATE_EXISTS', 'message' => 'A template for this message, language and product already exists. Edit it instead.'], 409);
            }

            try {
                $template = Template::create([
                    'slug' => $data['slug'],
                    'channel' => $data['channel'],
                    'language' => $data['language'],
                    'product_id' => $productId,
                    'category_id' => $categoryId,
                    'subject' => $data['subject'] ?? null,
                    'body' => $data['body'],
                    'is_active' => $data['is_active'] ?? true,
                    'updated_by' => $request->user()->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                return response()->json(['status' => 'error', 'code' => 'TEMPLATE_EXISTS', 'message' => 'A template for this message, language and product already exists. Edit it instead.'], 409);
            }

            $this->logActivity('CREATE', 'Template', "Created template {$template->slug} ({$template->language->value})");

            return response()->json([
                'status' => 'success',
                'message' => 'Template created successfully',
                'data' => new TemplateResource($template->load(['product', 'category'])),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create template', $th);
        }
    }

    public function update(TemplateRequest $request, int $id)
    {
        try {
            $template = Template::find($id);

            if (! $template) {
                return $this->notFound('Template not found');
            }

            $data = $request->validated();

            if (isset($data['body']) && $event = NotificationEvent::tryFrom($template->slug)) {
                $this->templates->assertPlaceholders($data['body'], $event->placeholders());
            }

            $old = $template->only(['subject', 'body', 'is_active']);
            $template->update($data + ['updated_by' => $request->user()->id]);

            $this->logActivity('UPDATE', 'Template', "Updated template {$template->slug} ({$template->language->value})", ['old' => $old, 'new' => $data]);

            return response()->json([
                'status' => 'success',
                'message' => 'Template updated successfully',
                'data' => new TemplateResource($template->load(['product', 'category'])),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update template', $th);
        }
    }

    /**
     * What was sent, to whom (masked) and with what result. Restricted to loans of the user's
     * branch; messages without a loan are visible to head office only.
     */
    public function logs(Request $request)
    {
        try {
            $query = NotificationLog::with('loan')->latest('created_at');

            if (BranchAccess::restrictedBranchId() !== null) {
                $query->whereHas('loan');
            }

            foreach (['event', 'status', 'channel'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where($filter, $request->get($filter));
                }
            }

            if ($request->filled('loan_public_id')) {
                $query->whereHas('loan', fn ($q) => $q->where('public_id', $request->loan_public_id));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Notification log retrieved successfully',
                'data' => $this->paginated($query->paginate($this->perPage($request)), NotificationLogResource::class),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve notification log', $th);
        }
    }
}
