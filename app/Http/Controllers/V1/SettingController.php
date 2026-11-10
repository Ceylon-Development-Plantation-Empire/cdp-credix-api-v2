<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreSettingRequest;
use App\Http\Requests\Settings\UpdateSettingRequest;
use App\Http\Resources\Settings\SettingResource;
use App\Models\Setting;
use App\Services\Settings\SettingService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SettingController extends Controller implements HasMiddleware
{
    use HandlesApiErrors;

    public function __construct(
        protected SettingService $settings,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Setting Index', only: ['index', 'show']),
            new Middleware('permission:Setting Create', only: ['store']),
            new Middleware('permission:Setting Update', only: ['update']),
            new Middleware('permission:Setting Delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $query = Setting::query()->orderBy('group')->orderBy('key');

            if ($request->filled('group')) {
                $query->where('group', $request->group);
            }

            if ($request->filled('search')) {
                $query->search($request->search);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Settings retrieved successfully',
                'data' => SettingResource::collection($query->get()),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve settings', $th);
        }
    }

    public function show(string $key)
    {
        try {
            $setting = Setting::where('key', $key)->first();

            if (! $setting) {
                return $this->notFound('Setting not found');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Setting retrieved successfully',
                'data' => new SettingResource($setting),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to retrieve setting', $th);
        }
    }

    public function store(StoreSettingRequest $request)
    {
        try {
            $setting = $this->settings->create($request->validated(), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Setting created successfully',
                'data' => new SettingResource($setting),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return $this->serverError('Failed to create setting', $th);
        }
    }

    public function destroy(string $key)
    {
        try {
            $setting = Setting::where('key', $key)->first();

            if (! $setting) {
                return $this->notFound('Setting not found');
            }

            $this->settings->delete($setting);

            return response()->json([
                'status' => 'success',
                'message' => 'Setting deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to delete setting', $th);
        }
    }

    /**
     * The value is validated by the rules stored on the setting row (see SettingService).
     * The change is audited there with the old and new value.
     */
    public function update(UpdateSettingRequest $request, string $key)
    {
        try {
            $setting = Setting::where('key', $key)->first();

            if (! $setting) {
                return $this->notFound('Setting not found');
            }

            $setting = $this->settings->update($setting, $request->input('value'), $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Setting updated successfully',
                'data' => new SettingResource($setting),
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError('Failed to update setting', $th);
        }
    }
}
