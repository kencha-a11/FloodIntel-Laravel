<?php

namespace App\Http\Controllers\Api;

use App\Enums\FloodReportStatus;
use App\Enums\UserAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\OverrideFloodReportStatusRequest;
use App\Http\Requests\Report\RejectFloodReportRequest;
use App\Http\Requests\Report\ReturnFloodReportRequest;
use App\Http\Requests\Report\StoreFloodReportRequest;
use App\Http\Requests\Report\ValidatedFeedRequest;
use App\Http\Requests\Report\ValidateFloodReportRequest;
use App\Http\Resources\FloodReportResource;
use App\Http\Resources\ValidatedReportFeedResource;
use App\Jobs\ResolveReportAddress;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\User;
use App\Realtime\ReportBroadcast;
use App\Services\FloodReportTransitions;
use App\Services\ReportAnomalyDetector;
use App\Support\ConsolidationWindow;
use App\Support\ImageSanitizer;
use App\Support\InvalidUploadedImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            ->when($request->filled('alert_level'), fn ($query) => $query->where('alert_level', $request->string('alert_level')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('barangay_id') && $user->hasAbility(UserAbility::ViewAllBarangays), fn ($query) => $query->where('barangay_id', $request->integer('barangay_id')))
            ->when($request->filled('from'), fn ($query) => $query->where('reported_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('reported_at', '<=', $request->date('to')))
            ->latest('reported_at')
            ->paginate($request->integer('per_page', 15));

        return FloodReportResource::collection($reports);
    }

    /**
     * Per-barangay pending counts for the current consolidation window (FR-27, D-24).
     */
    public function deadlines(): JsonResponse
    {
        $window = ConsolidationWindow::current();
        $start = $window->startsAt->utc();
        $end = $window->nextCutoffAt->utc();

        $pending = FloodReport::query()
            ->where('status', FloodReportStatus::Submitted)
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($query) use ($start, $end): void {
                    $query->whereNotNull('reported_at')
                        ->where('reported_at', '>=', $start)
                        ->where('reported_at', '<', $end);
                })->orWhere(function ($query) use ($start, $end): void {
                    $query->whereNull('reported_at')
                        ->where('created_at', '>=', $start)
                        ->where('created_at', '<', $end);
                });
            });

        $counts = (clone $pending)
            ->selectRaw('barangay_id, count(*) as pending')
            ->groupBy('barangay_id')
            ->pluck('pending', 'barangay_id');

        $fallbackRows = (clone $pending)->whereNull('reported_at')->count();

        $byBarangay = Barangay::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Barangay $barangay): array => [
                'barangay_id' => $barangay->id,
                'barangay' => $barangay->name,
                'pending' => (int) ($counts[$barangay->id] ?? 0),
            ])
            ->values();

        return response()->json(['data' => [
            'timezone' => $window->timezone,
            'cutoffs' => $window->cutoffs,
            'now' => $window->now->toIso8601String(),
            'window' => [
                'starts_at' => $window->startsAt->toIso8601String(),
                'next_cutoff_at' => $window->nextCutoffAt->toIso8601String(),
                'label' => $window->label(),
            ],
            'pending' => [
                'basis' => $fallbackRows > 0 ? 'reported_at_with_created_at_fallback' : 'reported_at',
                'total' => (int) $counts->sum(),
                'by_barangay' => $byBarangay,
            ],
        ]]);
    }

    /**
     * Feed of validated reports for map consumption (FR-24, FR-49, FR-50).
     */
    public function validatedFeed(ValidatedFeedRequest $request): AnonymousResourceCollection
    {
        $limit = $request->integer('limit', 300);

        $reports = FloodReport::query()
            ->validated()
            ->with('barangay')
            ->when($request->filled('min_lat'), fn ($query) => $query
                ->whereBetween('latitude', [$request->float('min_lat'), $request->float('max_lat')])
                ->whereBetween('longitude', [$request->float('min_lng'), $request->float('max_lng')]))
            ->when($request->filled('barangay_id'), fn ($query) => $query->where('barangay_id', $request->integer('barangay_id')))
            ->when($request->filled('alert_level'), fn ($query) => $query->where('alert_level', $request->string('alert_level')))
            ->when($request->filled('from'), fn ($query) => $query->where('reported_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('reported_at', '<=', $request->date('to')))
            ->latest('reported_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $truncated = $reports->count() > $limit;
        $page = $reports->take($limit);

        return ValidatedReportFeedResource::collection($page)
            ->additional([
                'meta' => [
                    'limit' => $limit,
                    'count' => $page->count(),
                    'truncated' => $truncated,
                ],
            ]);
    }

    /**
     * Store a new flood level report.
     */
    public function store(
        StoreFloodReportRequest $request,
        ReportAnomalyDetector $anomalyDetector,
        ReportBroadcast $broadcast,
        FloodReportTransitions $transitions,
    ): JsonResponse {
        $user = $request->user();

        if (blank($user->barangay_id)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'barangay_id' => ['A barangay is required for your account to submit a flood report.'],
                ],
            ], 422);
        }

        $path = $this->storePhoto($request->file('photo'), $user->id);

        $latitude = (float) $request->input('latitude');
        $longitude = (float) $request->input('longitude');
        $reportedAt = $request->date('reported_at') ?? now();

        $report = DB::transaction(function () use ($request, $user, $path, $latitude, $longitude, $reportedAt, $anomalyDetector, $transitions): FloodReport {
            $report = FloodReport::create([
                'user_id' => $user->id,
                'barangay_id' => $user->barangay_id,
                'flood_level' => $request->input('flood_level'),
                'alert_level' => $request->input('alert_level'),
                'flood_type' => $request->input('flood_type'),
                'depth_cm' => $request->input('depth_cm'),
                'landmark' => $request->input('landmark'),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'gps_accuracy' => $request->input('gps_accuracy'),
                'photo_path' => $path,
                'notes' => $request->input('notes'),
                'reported_at' => $reportedAt,
                'status' => FloodReportStatus::Submitted,
                'anomaly_flags' => $anomalyDetector->detect($user, $latitude, $longitude, $reportedAt),
            ]);

            $transitions->submit($report, $user);

            return $report;
        });

        $broadcast->created($report);

        ResolveReportAddress::dispatchAfterResponse($report->id);

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

        return new FloodReportResource($floodReport->load(['user', 'barangay', 'statusChanges']));
    }

    /**
     * Reject an invalid flood report.
     */
    public function reject(
        RejectFloodReportRequest $request,
        FloodReport $floodReport,
        ReportBroadcast $broadcast,
        FloodReportTransitions $transitions,
    ): FloodReportResource {
        $transitions->reject(
            $floodReport,
            $request->user(),
            $request->string('rejection_reason')->toString(),
        );

        $broadcast->statusChanged($floodReport);

        return $this->detail($floodReport);
    }

    /**
     * Validate a submitted flood report (FR-23).
     */
    public function validateReport(
        ValidateFloodReportRequest $request,
        FloodReport $floodReport,
        ReportBroadcast $broadcast,
        FloodReportTransitions $transitions,
    ): FloodReportResource {
        $transitions->validate($floodReport, $request->user());

        $broadcast->statusChanged($floodReport);

        return $this->detail($floodReport);
    }

    /**
     * Return a submitted flood report for correction (FR-23).
     */
    public function returnReport(
        ReturnFloodReportRequest $request,
        FloodReport $floodReport,
        ReportBroadcast $broadcast,
        FloodReportTransitions $transitions,
    ): FloodReportResource {
        $transitions->return(
            $floodReport,
            $request->user(),
            $request->string('return_reason')->toString(),
        );

        $broadcast->statusChanged($floodReport);

        return $this->detail($floodReport);
    }

    /**
     * Override a staff decision on a flood report (FR-25).
     */
    public function overrideReport(
        OverrideFloodReportStatusRequest $request,
        FloodReport $floodReport,
        ReportBroadcast $broadcast,
        FloodReportTransitions $transitions,
    ): FloodReportResource {
        $transitions->override(
            $floodReport,
            $request->user(),
            FloodReportStatus::from($request->string('to_status')->toString()),
            $request->string('reason')->toString(),
        );

        $broadcast->statusChanged($floodReport);

        return $this->detail($floodReport);
    }

    private function detail(FloodReport $floodReport): FloodReportResource
    {
        return new FloodReportResource(
            $floodReport->load(['user', 'barangay', 'rejectedBy', 'statusChanges']),
        );
    }

    private function storePhoto(UploadedFile $photo, int $userId): string
    {
        try {
            $image = ImageSanitizer::sanitize($photo->get());
        } catch (InvalidUploadedImage) {
            throw ValidationException::withMessages([
                'photo' => ['The photo could not be read as a valid JPEG, PNG, or WebP image.'],
            ]);
        }

        $path = sprintf(
            'flood-reports/%d/%s/%s.%s',
            $userId,
            now()->format('Y-m'),
            Str::random(40),
            $image['extension'],
        );

        Storage::disk('public')->put($path, $image['bytes']);

        return $path;
    }
}
