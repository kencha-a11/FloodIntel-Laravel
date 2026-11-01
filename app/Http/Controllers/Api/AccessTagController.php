<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tag\AccessTagFiltersRequest;
use App\Http\Requests\Tag\StoreAccessTagRequest;
use App\Http\Resources\AccessTagResource;
use App\Models\AccessTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccessTagController extends Controller
{
    /**
     * List the tags visible to the authenticated user (FR-119, FR-120).
     */
    public function index(AccessTagFiltersRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $tags = AccessTag::query()
            ->visibleTo($user)
            ->when($request->filled('barangay_id') && $user->hasAbility(UserAbility::ViewAllBarangays), fn (Builder $query) => $query->where('barangay_id', $request->integer('barangay_id')))
            ->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->string('type')))
            ->when($request->filled('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('from'), fn (Builder $query) => $query->where('observed_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('observed_at', '<=', $request->date('to')))
            ->with(['barangay', 'createdBy'])
            ->latest('observed_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return AccessTagResource::collection($tags);
    }

    /**
     * Record a tag for the caller's own barangay (FR-114, FR-115, FR-118).
     */
    public function store(StoreAccessTagRequest $request): JsonResponse
    {
        $user = $request->user();

        // A tag with no barangay cannot be scoped, filtered, or dispatched
        // against, so the refusal lands before any row exists (FR-115).
        if (blank($user->barangay_id)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'barangay_id' => ['A barangay is required for your account to record an access tag.'],
                ],
            ], 422);
        }

        $tag = AccessTag::create([
            'barangay_id' => $user->barangay_id,
            'created_by' => $user->id,
            'type' => $request->input('type'),
            'landmark' => $request->input('landmark'),
            'note' => $request->input('note'),
            'latitude' => (float) $request->input('latitude'),
            'longitude' => (float) $request->input('longitude'),
            'gps_accuracy' => $request->input('gps_accuracy'),
            'address' => $request->input('address'),
            // The capture instant, never the receipt time (FR-114, D-06).
            'observed_at' => $request->date('observed_at'),
            'is_active' => true,
        ]);

        return (new AccessTagResource($tag->load(['barangay', 'createdBy'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display a single tag.
     */
    public function show(AccessTag $accessTag): AccessTagResource
    {
        $this->authorize('view', $accessTag);

        return new AccessTagResource($accessTag->load(['barangay', 'createdBy']));
    }
}
