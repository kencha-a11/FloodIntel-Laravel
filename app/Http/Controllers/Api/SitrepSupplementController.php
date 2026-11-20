<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sitrep\StoreSitrepSupplementRequest;
use App\Http\Requests\Sitrep\UpdateSitrepSupplementRequest;
use App\Http\Resources\SitrepSupplementResource;
use App\Models\SituationalReportSupplement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mutable command-center supplement drafts (D-147). Once a snapshot is
 * prepared its content is frozen; edits here affect only future revisions.
 */
class SitrepSupplementController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['event_id' => ['required', 'integer', 'exists:flood_events,id']]);

        $supplements = SituationalReportSupplement::query()
            ->where('event_id', $request->integer('event_id'))
            ->with('barangay')
            ->orderBy('id')
            ->get();

        return SitrepSupplementResource::collection($supplements);
    }

    public function store(StoreSitrepSupplementRequest $request): JsonResponse
    {
        $user = $request->user();

        $supplement = SituationalReportSupplement::query()->create([
            ...$request->validated(),
            'created_by_user_id' => $user->id,
            'created_by_name' => $user->full_name,
        ]);

        return (new SitrepSupplementResource($supplement->load('barangay')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateSitrepSupplementRequest $request, SituationalReportSupplement $supplement): SitrepSupplementResource
    {
        $supplement->update($request->validated());

        return new SitrepSupplementResource($supplement->load('barangay'));
    }

    public function destroy(SituationalReportSupplement $supplement): JsonResponse
    {
        $supplement->delete();

        return response()->json(null, 204);
    }
}
