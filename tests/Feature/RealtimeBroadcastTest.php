<?php

use App\Enums\FloodReportStatus;
use App\Models\FloodReport;
use App\Realtime\ReportBroadcast;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.supabase.url' => 'https://project.supabase.co',
        'services.supabase.anon_key' => 'anon-key',
        'services.supabase.service_role_key' => 'service-role-key',
        'services.supabase.jwt_secret' => 'jwt-secret',
        'services.supabase.broadcast_timeout' => 2,
    ]);
});

function realtimeReport(array $overrides = []): FloodReport
{
    $report = new FloodReport;

    $report->forceFill(array_merge([
        'id' => 42,
        'user_id' => 7,
        'barangay_id' => 3,
        'status' => FloodReportStatus::Submitted,
        'flood_level' => 'knee',
        'latitude' => 14.95,
        'longitude' => 120.75,
        'photo_path' => 'flood-reports/secret.jpg',
        'notes' => 'secret note',
        'address' => 'secret address',
    ], $overrides));

    return $report;
}

it('publishes only the declared payload to the three declared topics', function () {
    Http::fake(['project.supabase.co/*' => Http::response([], 202)]);

    app(ReportBroadcast::class)->created(realtimeReport());

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toBe('https://project.supabase.co/realtime/v1/api/broadcast?private=true')
            ->and($request->header('apikey'))->toBe(['service-role-key'])
            ->and($request->header('Authorization'))->toBe(['Bearer service-role-key']);

        $messages = $request->data()['messages'];

        expect($messages)->toHaveCount(3)
            ->and(array_column($messages, 'topic'))->toEqualCanonicalizing([
                'reports:all',
                'reports:barangay:3',
                'reports:user:7',
            ]);

        foreach ($messages as $message) {
            expect($message['event'])->toBe('report.created')
                ->and($message['private'])->toBeTrue()
                ->and(array_keys($message['payload']))->toEqualCanonicalizing([
                    'id', 'barangay_id', 'status', 'changed',
                ]);

            $payload = json_encode($message['payload'], JSON_THROW_ON_ERROR);

            foreach (['latitude', 'longitude', 'photo', 'notes', 'address', 'user', 'secret'] as $forbidden) {
                expect($payload)->not->toContain($forbidden);
            }
        }

        return true;
    });
});

it('announces only the status field for a status change', function () {
    Http::fake(['project.supabase.co/*' => Http::response([], 202)]);

    app(ReportBroadcast::class)->statusChanged(
        realtimeReport(['status' => FloodReportStatus::Rejected, 'rejected_at' => now()]),
        ['status', 'rejected_by', 'latitude', 'photo_path'],
    );

    Http::assertSent(function (Request $request): bool {
        $message = $request->data()['messages'][0];

        expect($message['event'])->toBe('report.status_changed')
            ->and($message['payload']['status'])->toBe('rejected')
            ->and($message['payload']['changed'])->toBe(['status']);

        return true;
    });
});

it('does not publish until the surrounding transaction commits', function () {
    Http::fake(['project.supabase.co/*' => Http::response([], 202)]);

    $broadcast = app(ReportBroadcast::class);
    $report = realtimeReport();

    try {
        DB::transaction(function () use ($broadcast, $report): void {
            $broadcast->created($report);

            Http::assertNothingSent();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // The rollback is the scenario under test.
    }

    Http::assertNothingSent();

    DB::transaction(fn () => $broadcast->created($report));

    Http::assertSentCount(1);
});

it('stays silent when realtime is not configured', function () {
    config([
        'services.supabase.url' => null,
        'services.supabase.service_role_key' => null,
    ]);
    Http::fake();

    app(ReportBroadcast::class)->created(realtimeReport());

    Http::assertNothingSent();
});

it('stays silent when the service-role key is missing', function () {
    config(['services.supabase.service_role_key' => null]);
    Http::fake();

    app(ReportBroadcast::class)->created(realtimeReport());

    Http::assertNothingSent();
});

it('logs a rejected publish instead of failing the write', function () {
    Http::fake(['project.supabase.co/*' => Http::response(['message' => 'rejected'], 500)]);

    app(ReportBroadcast::class)->created(realtimeReport());

    Http::assertSentCount(1);
});
