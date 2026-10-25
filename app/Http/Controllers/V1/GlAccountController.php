<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Enums\GlAccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\GlAccountRequest;
use App\Http\Resources\Accounting\GlAccountResource;
use App\Models\GlAccount;
use App\Traits\ActivityLogTrait;
use App\Traits\DeletesRecords;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The chart of accounts. System accounts can be renamed and renumbered only; an account that
 * already has entries keeps its type, so its past balances keep their meaning.
 */
class GlAccountController extends Controller implements HasMiddleware
{
    use ActivityLogTrait, DeletesRecords, HandlesApiErrors;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:GL Account Index', only: ['index', 'show']),
            new Middleware('permission:GL Account Create', only: ['store']),
            new Middleware('permission:GL Account Update', only: ['update']),
            new Middleware('permission:GL Account Delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = GlAccount::orderBy('code');

            if ($request->filled('type')) {
                $query->where('type', $request->get('type'));
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'GL accounts retrieved successfully',
                'data' => GlAccountResource::collection($query->get()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve GL accounts', $th);
        }
    }

    public function show(int $id)
    {
        try {
            $account = GlAccount::find($id);

            if (! $account) {
                return $this->notFound('GL account not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'GL account retrieved successfully',
                'data' => new GlAccountResource($account),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve GL account', $th);
        }
    }

    public function store(GlAccountRequest $request)
    {
        try {
            $account = GlAccount::create($request->validated() + ['updated_by' => $request->user()->id]);

            $this->logActivity('CREATE', 'GlAccount', "Created GL account {$account->code} {$account->name}");

            return response()->json([
                'status' => 'success',
                'message' => 'GL account created successfully',
                'data' => new GlAccountResource($account),
            ], 201);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create GL account', $th);
        }
    }

    public function update(GlAccountRequest $request, int $id)
    {
        try {
            $account = GlAccount::find($id);

            if (! $account) {
                return $this->notFound('GL account not found');
            }

            $data = $request->validated();
            $retyped = isset($data['type']) && GlAccountType::from($data['type']) !== $account->type;

            if ($account->isSystem() && ($retyped || (array_key_exists('is_active', $data) && ! $data['is_active']))) {
                return response()->json(['status' => 'error', 'code' => 'SYSTEM_ACCOUNT', 'message' => 'A system account can be renamed or renumbered, but not retyped or deactivated.'], 422);
            }

            if ($retyped && $account->lines()->exists()) {
                return response()->json(['status' => 'error', 'code' => 'ACCOUNT_IN_USE', 'message' => 'An account with entries cannot change type.'], 422);
            }

            $before = $account->only(['code', 'name', 'type', 'is_active']);
            $account->update($data + ['updated_by' => $request->user()->id]);

            $this->logActivity('UPDATE', 'GlAccount', "Updated GL account {$account->code}", [
                'before' => $before,
                'after' => $account->only(['code', 'name', 'type', 'is_active']),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'GL account updated successfully',
                'data' => new GlAccountResource($account),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update GL account', $th);
        }
    }

    public function destroy(int $id)
    {
        try {
            return $this->destroyRecord(GlAccount::find($id), 'GL account', 'GlAccount', fn (GlAccount $account) => match (true) {
                $account->isSystem() => 'A system account cannot be deleted.',
                $account->lines()->exists() => 'An account with entries cannot be deleted. Deactivate it instead.',
                default => null,
            });
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete GL account', $th);
        }
    }
}
