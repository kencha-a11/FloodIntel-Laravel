<?php

use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\RealtimeLatencySample;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'services.supabase.url' => 'https://project.supabase.co',
        'services.supabase.anon_key' => 'anon-key',
        'services.supabase.service_role_key' => 'service-role-key',
        'services.supabase.jwt_secret' => 'jwt-secret',
        'services.supabase.realtime_token_ttl' => 600,
    ]);
});

function realtimeClaims(string $token): array
{
    $parts = explode('.', $token);

    expect($parts)->toHaveCount(3);

    return json_decode(
        base64_decode(strtr($parts[1], '-_', '+/')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

it('rejects a guest from the channel authorization', function () {
    $this->getJson('/api/realtime/authorization')->assertUnauthorized();
});

it('rejects an unverified user from the channel authorization', function () {
    $user = User::factory()->tanod()->unverified()->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/realtime/authorization')
        ->assertForbidden();
});

it('reports realtime as disabled when the deployment is not configured', function () {
    config([
        'services.supabase.url' => null,
        'services.supabase.service_role_key' => null,
    ]);

    $user = User::factory()->officer()->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/realtime/authorization')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonMissingPath('data.token')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('derives a command-center scope and a signed token on the server', function () {
    $user = User::factory()->officer()->create();

    $response = $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/realtime/authorization?topics[]=reports:barangay:999&barangay_id=999')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertHeader('Cache-Control', 'no-store, private');

    $data = $response->json('data');

    expect($data['topics'])->toBe(['reports:all', 'reports:user:'.$user->id])
        ->and($data['url'])->toBe('https://project.supabase.co')
        ->and($data['anon_key'])->toBe('anon-key');

    $claims = realtimeClaims($data['token']);

    expect($claims['sub'])->toBe((string) $user->id)
        ->and($claims['role'])->toBe('authenticated')
        ->and($claims['aud'])->toBe('authenticated')
        ->and($claims['exp'] - $claims['iat'])->toBe(600);

    $parts = explode('.', $data['token']);
    $expected = rtrim(strtr(base64_encode(hash_hmac(
        'sha256',
        $parts[0].'.'.$parts[1],
        'jwt-secret',
        true,
    )), '+/', '-_'), '=');

    expect($parts[2])->toBe($expected);
});

it('derives a barangay scope from the user and ignores a client-supplied one', function () {
    $barangay = Barangay::factory()->create();
    $other = Barangay::factory()->create();
    $user = User::factory()->tanod()->forBarangay($barangay)->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson("/api/realtime/authorization?barangay_id={$other->id}")
        ->assertOk()
        ->assertJsonPath('data.topics', [
            'reports:barangay:'.$barangay->id,
            'reports:user:'.$user->id,
        ]);
});

it('records a latency sample against the server receipt instant', function () {
    $user = User::factory()->tanod()->create();
    $report = FloodReport::factory()->create([
        'user_id' => $user->id,
        'barangay_id' => $user->barangay_id,
    ]);

    $this->withToken($user->createToken('t')->plainTextToken)
        ->postJson('/api/realtime/latency', [
            'report_id' => $report->id,
            'event' => 'report.created',
            'applied_at' => $report->created_at->copy()->addMilliseconds(800)->format('Y-m-d\TH:i:s.vP'),
        ])
        ->assertNoContent();

    $sample = RealtimeLatencySample::sole();

    expect($sample->latency_ms)->toBe(800)
        ->and($sample->event)->toBe('report.created')
        ->and($sample->received_at->equalTo($report->created_at))->toBeTrue();
});

it('refuses a latency sample for a report the caller cannot see', function () {
    $report = FloodReport::factory()->create();
    $outsider = User::factory()->tanod()->create();

    $this->withToken($outsider->createToken('t')->plainTextToken)
        ->postJson('/api/realtime/latency', [
            'report_id' => $report->id,
            'event' => 'report.created',
            'applied_at' => now()->toIso8601String(),
        ])
        ->assertForbidden();

    expect(RealtimeLatencySample::count())->toBe(0);
});

it('stores an unusable instant as an excluded sample', function () {
    $user = User::factory()->tanod()->create();
    $report = FloodReport::factory()->create([
        'user_id' => $user->id,
        'barangay_id' => $user->barangay_id,
    ]);

    $this->withToken($user->createToken('t')->plainTextToken)
        ->postJson('/api/realtime/latency', [
            'report_id' => $report->id,
            'event' => 'report.created',
            'applied_at' => $report->created_at->copy()->subSecond()->toIso8601String(),
        ])
        ->assertNoContent();

    expect(RealtimeLatencySample::sole()->latency_ms)->toBeNull();
});

it('publishes the latency summary with its sample and exclusion counts', function () {
    $officer = User::factory()->officer()->create();
    $report = FloodReport::factory()->create();

    foreach ([1000, 2000, 5000, null] as $latency) {
        RealtimeLatencySample::create([
            'report_id' => $report->id,
            'event' => 'report.created',
            'received_at' => now()->subSecond(),
            'applied_at' => now(),
            'latency_ms' => $latency,
        ]);
    }

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/realtime/latency?hours=24')
        ->assertOk()
        ->assertJsonPath('data.window_hours', 24)
        ->assertJsonPath('data.samples', 3)
        ->assertJsonPath('data.excluded', 1)
        ->assertJsonPath('data.median_ms', 2000)
        ->assertJsonPath('data.max_ms', 5000)
        ->assertJsonPath('data.bound_ms', 3000)
        ->assertJsonPath('data.breaches', 1);
});

it('refuses the latency summary to a field reporter', function () {
    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->getJson('/api/realtime/latency')
        ->assertForbidden();
});
