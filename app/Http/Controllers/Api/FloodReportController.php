<?php

namespace App\Http\Controllers\Api;

use App\Enums\FloodReportStatus;
use App\Enums\UserAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\RejectFloodReportRequest;
use App\Http\Requests\Report\StoreFloodReportRequest;
use App\Http\Resources\FloodReportResource;
use App\Models\FloodReport;
use App\Models\User;
use App\Services\ReverseGeocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FloodReportController extends Controller
{
    /**
     * List flood reports visible to the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $reports = FloodReport::query()
            ->visibleTo($user)
            ->with(['user', 'barangay'])
            ->when($request->filled('flood_level'), fn ($query) => $query->where('flood_level', $request->string('flood_level')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('barangay_id') && $user->hasAbility(UserAbility::ViewAllBarangays), fn ($query) => $query->where('barangay_id', $request->integer('barangay_id')))
            ->when($request->filled('from'), fn ($query) => $query->where('reported_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('reported_at', '<=', $request->date('to')))
            ->latest('reported_at')
            ->paginate($request->integer('per_page', 15));

        return FloodReportResource::collection($reports);
    }

    /**
     * Store a new flood level report.
     */
    public function store(
        StoreFloodReportRequest $request,
        ReverseGeocoder $geocoder,
    ): JsonResponse {
        $user = $request->user();

        $path = $request->file('photo')->store(
            'flood-reports/'.$user->id.'/'.now()->format('Y-m'),
            'public',
        );

        $latitude = (float) $request->input('latitude');
        $longitude = (float) $request->input('longitude');

        $report = FloodReport::create([
            'user_id' => $user->id,
            'barangay_id' => $user->barangay_id,
            'flood_level' => $request->string('flood_level')->toString(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'gps_accuracy' => $request->input('gps_accuracy'),
            'address' => $geocoder->lookup($latitude, $longitude),
            'photo_path' => $path,
            'notes' => $request->input('notes'),
            'reported_at' => $request->input('reported_at') ?: now(),
            'status' => FloodReportStatus::Submitted,
        ]);

        return (new FloodReportResource($report->load(['user', 'barangay'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display a single flood report.
     */
    public function show(FloodReport $floodReport): FloodReportResource
    {
        $this->authorize('view', $floodReport);

        return new FloodReportResource($floodReport->load(['user', 'barangay']));
    }

    /**
     * Reject an invalid flood report.
     */
    public function reject(RejectFloodReportRequest $request, FloodReport $floodReport): FloodReportResource
    {
        $floodReport->status = FloodReportStatus::Rejected;
        $floodReport->rejected_at = now();
        $floodReport->rejected_by = $request->user()->id;
        $floodReport->rejection_reason = $request->string('rejection_reason')->toString();
        $floodReport->save();

        return new FloodReportResource($floodReport->load(['user', 'barangay', 'rejectedBy']));
    }
}
