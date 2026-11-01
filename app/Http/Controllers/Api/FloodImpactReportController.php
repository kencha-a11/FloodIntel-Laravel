<?php

namespace App\Http\Controllers\Api;

use App\Enums\FloodImpactStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\StoreFloodImpactReportRequest;
use App\Http\Resources\FloodImpactReportResource;
use App\Models\FloodEvent;
use App\Models\FloodImpactReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FloodImpactReportController extends Controller
{
    public function store(StoreFloodImpactReportRequest $request): JsonResponse
    {
        $user = $request->user();

        if (blank($user->barangay_id)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'barangay_id' => ['A barangay is required for your account to submit an impact report.'],
                ],
            ], 422);
        }

        $event = FloodEvent::find($request->input('event_id'));
        if (! $event || $event->is_closed) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['event_id' => ['The event is not available for filing.']],
            ], 422);
        }

        $report = FloodImpactReport::create([
            'user_id' => $user->id,
            'barangay_id' => $user->barangay_id,
            'event_id' => $event->id,
            'families' => $request->input('families'),
            'individuals' => $request->input('individuals'),
            'breakdown' => $request->input('breakdown'),
            'related_level_report_ids' => $request->input('related_level_report_ids'),
            'remarks' => $request->input('remarks'),
            'supersedes_id' => $request->input('supersedes_id'),
            'reported_at' => $request->input('reported_at') ?: now(),
            'status' => FloodImpactStatus::Submitted,
        ]);

        return (new FloodImpactReportResource($report->load(['user', 'barangay', 'event'])))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $reports = FloodImpactReport::query()
            ->visibleTo($user)
            ->with(['user', 'barangay', 'event'])
            ->latest('reported_at')
            ->paginate($request->integer('per_page', 15));

        return FloodImpactReportResource::collection($reports);
    }
}
