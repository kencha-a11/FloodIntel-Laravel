<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sitrep\IssueSituationalReportRequest;
use App\Http\Requests\Sitrep\PrepareSituationalReportRequest;
use App\Http\Resources\SituationalReportResource;
use App\Models\SituationalReport;
use App\Models\SituationalReportRun;
use App\Services\SitrepDocxRenderer;
use App\Services\SitrepReferenceHtmlRenderer;
use App\Services\SituationalReportAggregator;
use App\Services\SituationalReportIssuer;
use App\Support\ConsolidationWindow;
use App\Support\SitrepDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpWord\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The command-center SITREP surface (D-42, D-43, D-147). Reads and writes
 * are limited to mdrrmo_officer / mdrrmo_staff by the route group and RLS;
 * preparation and issuance enforce their abilities in the services.
 */
class SituationalReportController extends Controller
{
    public function index(Request $request)
    {
        $reports = SituationalReport::query()
            ->with('issuance')
            ->orderByDesc('cutoff_at')
            ->orderByDesc('revision')
            ->paginate($request->integer('per_page', 15));

        return SituationalReportResource::collection($reports);
    }

    public function store(PrepareSituationalReportRequest $request, SituationalReportAggregator $aggregator): JsonResponse
    {
        $report = $aggregator->prepare(
            ConsolidationWindow::current(),
            $request->user(),
            null,
            $request->validated('event_id'),
        );

        return (new SituationalReportResource($report->load('issuance')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SituationalReport $situationalReport): SituationalReportResource
    {
        return new SituationalReportResource($situationalReport->load(['inclusions', 'issuance']));
    }

    /**
     * Per-cutoff preparation states (D-44): the latest recorded attempt per
     * cutoff, or a derived state — `pending` before the cutoff passes and
     * `not_run` when it passed with no attempt. `not_run` is computed, never
     * stored, so a missed cutoff is visible without a backdated row.
     */
    public function runsStatus(): JsonResponse
    {
        $timezone = (string) config('deadlines.timezone', 'Asia/Manila');
        $cutoffs = array_map('strval', (array) config('deadlines.cutoffs', ['04:00', '15:00']));
        $now = CarbonImmutable::now($timezone);

        $instants = [];

        foreach ([$now->startOfDay()->subDays(2), $now->startOfDay()->subDay(), $now->startOfDay(), $now->startOfDay()->addDay()] as $day) {
            foreach ($cutoffs as $cutoff) {
                [$hour, $minute] = array_map('intval', explode(':', $cutoff));
                $instants[] = $day->setTime($hour, $minute);
            }
        }

        usort($instants, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        $upcoming = array_values(array_filter($instants, fn (CarbonImmutable $i): bool => $i->greaterThan($now)));
        $passed = array_values(array_filter($instants, fn (CarbonImmutable $i): bool => $i->lessThanOrEqualTo($now)));
        $selected = [...array_slice($passed, -5), ...array_slice($upcoming, 0, 1)];

        $runs = SituationalReportRun::query()
            ->whereIn('cutoff_at', array_map(fn (CarbonImmutable $i) => $i->utc(), $selected))
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn ($run): string => $run->cutoff_at->utc()->toIso8601String());

        $rows = [];

        foreach ($selected as $instant) {
            $latest = $runs->get($instant->utc()->toIso8601String())?->first();
            $state = $instant->greaterThan($now) ? 'pending' : ($latest?->state ?? 'not_run');

            $rows[] = [
                'cutoff_at' => $instant->utc()->toIso8601String(),
                'cutoff_label' => $instant->setTimezone($timezone)->format('F j, Y g:i A'),
                'state' => $state,
                'reason' => $latest?->reason,
                'situational_report_id' => $latest?->situational_report_id,
                'triggered_by' => $latest?->triggered_by,
                'actor_name' => $latest?->actor_name,
                'recorded_at' => $latest?->created_at?->utc()->toIso8601String(),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * The document as the office's exact HTML form (owner directive,
     * 2026-11-20), with the memorandum filled from the frozen snapshot. The
     * client fetches this with its bearer token and renders it in a sandboxed
     * frame; there is no public or tokenized link (FR-86).
     */
    public function document(SituationalReport $situationalReport, SitrepReferenceHtmlRenderer $renderer): Response
    {
        return response($renderer->render(SitrepDocument::build($situationalReport)))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function docx(SituationalReport $situationalReport, SitrepDocxRenderer $renderer): StreamedResponse
    {
        $word = $renderer->render(SitrepDocument::build($situationalReport));
        $filename = sprintf('SITREP-revision-%d.docx', $situationalReport->id);

        return response()->streamDownload(function () use ($word): void {
            IOFactory::createWriter($word, 'Word2007')->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    public function issue(
        IssueSituationalReportRequest $request,
        SituationalReport $situationalReport,
        SituationalReportIssuer $issuer,
    ): SituationalReportResource {
        $issuer->issue($situationalReport, $request->user(), $request->office());

        return new SituationalReportResource($situationalReport->load(['inclusions', 'issuance']));
    }
}
