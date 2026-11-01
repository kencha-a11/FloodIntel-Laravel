<?php

namespace App\Http\Controllers\Api;

use App\Enums\FloodReportStatus;
use App\Enums\UserAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ExportFloodReportsRequest;
use App\Http\Requests\Report\OverrideFloodReportStatusRequest;
use App\Http\Requests\Report\RejectFloodReportRequest;
use App\Http\Requests\Report\ReportLogFiltersRequest;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FloodReportController extends Controller
{
    /**
     * The report log's columns. The photo is a URL, never its bytes: the CSV
     * is a review artifact, not a copy of the evidence store.
     *
     * @var array<int, string>
     */
    private const CSV_COLUMNS = [
        'report_id', 'barangay', 'flood_type', 'status', 'status_label',
        'alert_level', 'alert_level_label', 'reporter_name', 'reporter_role',
        'observed_at', 'received_at', 'latency_seconds', 'latitude', 'longitude',
        'address', 'depth_cm', 'notes', 'photo_url',
    ];

    /**
     * List flood reports visible to the authenticated user.
     */
    public function index(ReportLogFiltersRequest $request): AnonymousResourceCollection
    {
        $reports = $this->filteredReports($request)
            ->with(['user', 'barangay', 'supersededBy'])
            ->paginate($request->integer('per_page', 15));

        return FloodReportResource::collection($reports);
    }

    /**
     * Stream the filtered report log as CSV (FR-29).
     *
     * Rows are read in chunks inside the streamed response, so the export's
     * memory use is bounded by the chunk size rather than the log size.
     */
    public function export(ExportFloodReportsRequest $request): StreamedResponse
    {
        $reports = $this->filteredReports($request)->with(['user', 'barangay']);
        $count = (clone $reports)->count();

        return response()->streamDownload(function () use ($reports): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');

            $reports->chunk(500, function (Collection $chunk) use ($handle): void {
                foreach ($chunk as $report) {
                    fputcsv($handle, $this->csvRow($report), ',', '"', '');
                }
            });

            fclose($handle);
        }, 'flood-report-log-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Report-Count' => (string) $count,
            'Cache-Control' => 'no-store',
        ]);
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

        // A replay is answered before any side effect, so the second request
        // stores no second photo (D-8, D-110).
        $clientUuid = $request->validated('client_uuid');
        $existing = $clientUuid === null ? null : $this->findByClientUuid($user, $clientUuid);

        if ($existing !== null) {
            return $this->reportResponse($existing, 200);
        }

        // Resolve the correction target before the photo is stored, so a
        // refused correction leaves no orphan file.
        $this->correctionTarget($request, $user);

        $path = $this->storePhoto($request->file('photo'), $user->id);

        $latitude = (float) $request->input('latitude');
        $longitude = (float) $request->input('longitude');
        $reportedAt = $request->date('reported_at') ?? now();

        try {
            $report = DB::transaction(function () use ($request, $user, $path, $latitude, $longitude, $reportedAt, $anomalyDetector, $transitions, $clientUuid): FloodReport {
                $previous = $this->correctionTarget($request, $user, lock: true);

                $report = FloodReport::create([
                    'user_id' => $user->id,
                    'barangay_id' => $user->barangay_id,
                    'client_uuid' => $clientUuid,
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
                    'supersedes_id' => $previous?->id,
                ]);

                $transitions->submit($report, $user);

                return $report;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent replay won the unique index between the look-up and
            // the insert. The photo this request stored belongs to no row, so
            // it is deleted and the winner is returned (D-110).
            Storage::disk('public')->delete($path);

            $winner = $clientUuid === null ? null : $this->findByClientUuid($user, $clientUuid);

            if ($winner === null) {
                throw $exception;
            }

            return $this->reportResponse($winner, 200);
        }

        $broadcast->created($report);

        ResolveReportAddress::dispatchAfterResponse($report->id);

        return $this->reportResponse($report, 201);
    }

    /**
     * Display a single flood report.
     */
    public function show(FloodReport $floodReport): FloodReportResource
    {
        $this->authorize('view', $floodReport);

        return new FloodReportResource($floodReport->load([
            'user',
            'barangay',
            'statusChanges',
            'supersedes.statusChanges',
            'supersededBy',
        ]));
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

    /**
     * The queue and the export share this query: one definition of "which rows
     * match", so the two surfaces cannot disagree (FR-29).
     *
     * The `id` tiebreaker makes `chunk()` deterministic when reports share an
     * observation instant.
     *
     * @return Builder<FloodReport>
     */
    private function filteredReports(ReportLogFiltersRequest $request): Builder
    {
        $user = $request->user();

        return FloodReport::query()
            ->visibleTo($user)
            ->when($request->filled('flood_level'), fn (Builder $query) => $query->where('flood_level', $request->string('flood_level')))
            ->when($request->filled('flood_type'), fn (Builder $query) => $query->where('flood_type', $request->string('flood_type')))
            ->when($request->filled('alert_level'), fn (Builder $query) => $query->where('alert_level', $request->string('alert_level')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('barangay_id') && $user->hasAbility(UserAbility::ViewAllBarangays), fn (Builder $query) => $query->where('barangay_id', $request->integer('barangay_id')))
            ->when($request->filled('q'), fn (Builder $query) => $this->applySearch($query, $request->string('q')->trim()->toString()))
            ->when($request->filled('from'), fn (Builder $query) => $query->where('reported_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('reported_at', '<=', $request->date('to')))
            ->latest('reported_at')
            ->orderByDesc('id');
    }

    /**
     * Match the free-text term against notes, landmark, address, and the
     * reporter's full name (FR-29).
     *
     * `LOWER` on both sides keeps the match case-insensitive on SQLite and
     * Postgres, and the explicit `ESCAPE` clause makes a typed `%` or `_` a
     * literal instead of a wildcard.
     *
     * @param  Builder<FloodReport>  $query
     * @return Builder<FloodReport>
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

        return $query->where(function (Builder $query) use ($pattern): void {
            $query->whereRaw("LOWER(notes) LIKE ? ESCAPE '\\'", [$pattern])
                ->orWhereRaw("LOWER(landmark) LIKE ? ESCAPE '\\'", [$pattern])
                ->orWhereRaw("LOWER(address) LIKE ? ESCAPE '\\'", [$pattern])
                ->orWhereHas('user', fn (Builder $user) => $user->whereRaw(
                    "LOWER(first_name || ' ' || last_name) LIKE ? ESCAPE '\\'",
                    [$pattern],
                ));
        });
    }

    /**
     * One CSV row. Missing values are empty cells, never the string "null".
     *
     * @return array<int, int|string>
     */
    private function csvRow(FloodReport $report): array
    {
        $observed = $report->reported_at;
        $received = $report->created_at;

        return [
            $report->id,
            $report->barangay->name,
            $report->flood_type?->label() ?? '',
            $report->status->value,
            $report->status->label(),
            $report->alert_level?->value ?? '',
            $report->alert_level?->label() ?? '',
            trim($report->user->first_name.' '.$report->user->last_name),
            $report->user->role->label(),
            $observed?->toIso8601String() ?? '',
            $received?->toIso8601String() ?? '',
            $observed === null || $received === null
                ? ''
                : $received->getTimestamp() - $observed->getTimestamp(),
            $report->latitude,
            $report->longitude,
            $report->address ?? '',
            $report->depth_cm ?? '',
            $report->notes ?? '',
            $report->photo_url,
        ];
    }

    /**
     * Resolve the returned report a submission corrects (FR-14).
     *
     * The target must be visible to the filer, still `returned`, and the head
     * of its chain: a correction answers a return, and a report is corrected
     * once. Callers pass `$lock` inside the write transaction so two
     * corrections of the same report cannot both pass the head check.
     */
    private function correctionTarget(
        StoreFloodReportRequest $request,
        User $user,
        bool $lock = false,
    ): ?FloodReport {
        if (! $request->filled('supersedes_id')) {
            return null;
        }

        $report = FloodReport::query()
            ->visibleTo($user)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->find((int) $request->input('supersedes_id'));

        if (
            $report === null
            || $report->status !== FloodReportStatus::Returned
            || $report->supersededBy()->exists()
        ) {
            throw ValidationException::withMessages([
                'supersedes_id' => 'Only the current returned report in a chain can be corrected.',
            ]);
        }

        return $report;
    }

    private function detail(FloodReport $floodReport): FloodReportResource
    {
        return new FloodReportResource(
            $floodReport->load([
                'user',
                'barangay',
                'rejectedBy',
                'statusChanges',
                'supersedes.statusChanges',
                'supersededBy',
            ]),
        );
    }

    private function findByClientUuid(User $user, string $clientUuid): ?FloodReport
    {
        return FloodReport::query()
            ->where('user_id', $user->id)
            ->where('client_uuid', $clientUuid)
            ->first();
    }

    private function reportResponse(FloodReport $report, int $status): JsonResponse
    {
        return (new FloodReportResource(
            $report->load(['user', 'barangay', 'supersedes.statusChanges']),
        ))->response()->setStatusCode($status);
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
