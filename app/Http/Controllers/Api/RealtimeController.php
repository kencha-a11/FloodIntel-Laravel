<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Realtime\RecordRealtimeLatencyRequest;
use App\Models\FloodReport;
use App\Models\RealtimeLatencySample;
use App\Models\User;
use App\Realtime\ChannelScope;
use App\Realtime\LatencyStatistics;
use App\Realtime\PropagationInstants;
use App\Realtime\RealtimeSettings;
use App\Realtime\RealtimeTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RealtimeController extends Controller
{
    /**
     * Issue the subscriber's channel scope and short-lived token.
     *
     * The scope is derived from the caller's identity; any topic, barangay, or
     * scope in the request is ignored (FR-68, D-65).
     */
    public function authorization(Request $request, RealtimeTokenIssuer $issuer): JsonResponse
    {
        $settings = RealtimeSettings::fromConfig();

        if (! $settings->enabled()) {
            return response()->json(['data' => ['enabled' => false]], 200, [
                'Cache-Control' => 'no-store',
            ]);
        }

        /** @var User $user */
        $user = $request->user();
        $token = $issuer->issue($user);

        if ($token === null) {
            return response()->json(['data' => ['enabled' => false]], 200, [
                'Cache-Control' => 'no-store',
            ]);
        }

        return response()->json([
            'data' => [
                'enabled' => true,
                'url' => $settings->url,
                'anon_key' => $settings->anonKey,
                'token' => $token['token'],
                'expires_at' => $token['expires_at'],
                'topics' => ChannelScope::forUser($user),
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Record the instant a subscriber applied a realtime update (FR-70).
     */
    public function recordLatency(RecordRealtimeLatencyRequest $request): Response
    {
        $report = FloodReport::findOrFail($request->integer('report_id'));

        $this->authorize('view', $report);

        $event = $request->string('event')->toString();
        $appliedAt = $request->date('applied_at');

        RealtimeLatencySample::create([
            'report_id' => $report->id,
            'event' => $event,
            'received_at' => PropagationInstants::receivedAt($report, $event),
            'applied_at' => $appliedAt,
            'latency_ms' => $appliedAt === null
                ? null
                : PropagationInstants::latencyMs($report, $event, $appliedAt),
        ]);

        return response()->noContent();
    }

    /**
     * The median and maximum latency for a stated window (TNFR-05, D-67).
     */
    public function latencySummary(Request $request): JsonResponse
    {
        $hours = min(168, max(1, $request->integer('hours', 24)));

        $latencies = RealtimeLatencySample::query()
            ->where('created_at', '>=', now()->subHours($hours))
            ->pluck('latency_ms')
            ->all();

        return response()->json([
            'data' => LatencyStatistics::summarize($latencies, $hours),
        ]);
    }
}
