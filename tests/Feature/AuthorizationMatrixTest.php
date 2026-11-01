<?php

use App\Enums\FloodImpactStatus;
use App\Enums\FloodReportStatus;
use App\Enums\UserAbility;
use App\Enums\UserRole;
use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\FloodImpactReport;
use App\Models\FloodReport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function authzToken(User $user): string
{
    return $user->createToken('matrix')->plainTextToken;
}

function authzFloodReportPayload(array $overrides = []): array
{
    return array_merge([
        'flood_level' => 'knee',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'gps_accuracy' => 12.5,
        'photo' => testPhoto(),
        'notes' => 'Water rising near the school.',
        'reported_at' => now()->subMinutes(5)->toIso8601String(),
    ], $overrides);
}

function authzImpactReport(User $user, Barangay $barangay): FloodImpactReport
{
    $event = FloodEvent::create([
        'title' => 'Tropical storm '.fake()->unique()->word(),
        'starts_at' => now()->subHour(),
    ]);

    return FloodImpactReport::create([
        'user_id' => $user->id,
        'barangay_id' => $barangay->id,
        'event_id' => $event->id,
        'families' => 4,
        'individuals' => 12,
        'reported_at' => now(),
        'status' => FloodImpactStatus::Submitted,
    ]);
}

it('serves the shared authenticated surface to every role', function (UserRole $role) {
    $user = User::factory()->role($role)->create();
    $token = authzToken($user);

    $this->withToken($token)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('role', $role->value);

    foreach ([
        '/api/flood-reports',
        '/api/flood-impact-reports',
        '/api/tokens',
    ] as $uri) {
        $this->withToken($token)->getJson($uri)->assertOk();
    }

    $this->withToken($token)->getJson('/api/realtime/authorization')->assertOk();
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
    'rescue leader' => UserRole::RescueLeader,
]);

it('derives the realtime scope from the role, not the request', function (UserRole $role, array $kinds) {
    config([
        'services.supabase.url' => 'https://project.supabase.co',
        'services.supabase.anon_key' => 'anon-key',
        'services.supabase.service_role_key' => 'service-role-key',
        'services.supabase.jwt_secret' => 'jwt-secret',
    ]);

    $barangay = Barangay::factory()->create();
    $user = User::factory()->role($role)->forBarangay($barangay)->create();

    $topics = $this->withToken(authzToken($user))
        ->getJson('/api/realtime/authorization?barangay_id=999&topics[]=reports:all')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->json('data.topics');

    $expected = array_map(fn (string $kind): string => match ($kind) {
        'all' => 'reports:all',
        'barangay' => 'reports:barangay:'.$barangay->id,
        'user' => 'reports:user:'.$user->id,
    }, $kinds);

    expect($topics)->toBe($expected);
})->with([
    'officer' => [UserRole::MdrrmoOfficer, ['all', 'user']],
    'staff' => [UserRole::MdrrmoStaff, ['all', 'user']],
    'captain' => [UserRole::BarangayCaptain, ['barangay', 'user']],
    'tanod' => [UserRole::BarangayTanod, ['barangay', 'user']],
    'rescue leader' => [UserRole::RescueLeader, ['user']],
]);

it('keeps token management on the caller tokens for every role', function (UserRole $role) {
    $user = User::factory()->role($role)->create();
    $token = authzToken($user);

    $this->withToken($token)
        ->getJson('/api/tokens')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $created = $this->withToken($token)
        ->postJson('/api/tokens', ['name' => 'matrix'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'matrix');

    $this->withToken($token)
        ->deleteJson('/api/tokens/'.$created->json('data.id'))
        ->assertNoContent();
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
    'rescue leader' => UserRole::RescueLeader,
]);

it('admits only field roles to submit flood reports', function (UserRole $role, int $status) {
    Storage::fake('public');
    Http::fake();

    $user = User::factory()->role($role)->create();

    $this->withToken(authzToken($user))
        ->post('/api/flood-reports', authzFloodReportPayload())
        ->assertStatus($status);
})->with([
    'captain' => [UserRole::BarangayCaptain, 201],
    'tanod' => [UserRole::BarangayTanod, 201],
    'officer' => [UserRole::MdrrmoOfficer, 403],
    'staff' => [UserRole::MdrrmoStaff, 403],
    'rescue leader' => [UserRole::RescueLeader, 403],
]);

it('admits only the captain to submit impact reports', function (UserRole $role, int $status) {
    $user = User::factory()->role($role)->create();
    $event = FloodEvent::create([
        'title' => 'Tropical storm',
        'starts_at' => now()->subHour(),
    ]);

    $this->withToken(authzToken($user))
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 4,
            'individuals' => 12,
            'reported_at' => now()->subMinutes(5)->toIso8601String(),
        ])
        ->assertStatus($status);
})->with([
    'captain' => [UserRole::BarangayCaptain, 201],
    'tanod' => [UserRole::BarangayTanod, 403],
    'officer' => [UserRole::MdrrmoOfficer, 403],
    'staff' => [UserRole::MdrrmoStaff, 403],
    'rescue leader' => [UserRole::RescueLeader, 403],
]);

it('guards the validated feed by role', function (UserRole $role, int $status) {
    $user = User::factory()->role($role)->create();
    FloodReport::factory()->create();

    $this->withToken(authzToken($user))
        ->getJson('/api/flood-reports/validated-feed')
        ->assertStatus($status);
})->with([
    'officer' => [UserRole::MdrrmoOfficer, 200],
    'staff' => [UserRole::MdrrmoStaff, 200],
    'rescue leader' => [UserRole::RescueLeader, 200],
    'captain' => [UserRole::BarangayCaptain, 403],
    'tanod' => [UserRole::BarangayTanod, 403],
]);

it('lets command center roles validate and return reports', function (UserRole $role, int $status) {
    $user = User::factory()->role($role)->create();
    $token = authzToken($user);
    $toValidate = FloodReport::factory()->create();
    $toReturn = FloodReport::factory()->create();

    $this->withToken($token)
        ->postJson("/api/flood-reports/{$toValidate->id}/validate")
        ->assertStatus($status);

    $this->withToken($token)
        ->postJson("/api/flood-reports/{$toReturn->id}/return", [
            'return_reason' => 'Recapture the photo.',
        ])
        ->assertStatus($status);
})->with([
    'officer' => [UserRole::MdrrmoOfficer, 200],
    'staff' => [UserRole::MdrrmoStaff, 200],
    'captain' => [UserRole::BarangayCaptain, 403],
    'tanod' => [UserRole::BarangayTanod, 403],
    'rescue leader' => [UserRole::RescueLeader, 403],
]);

it('restricts rejection to the officer route group', function (UserRole $role, int $status) {
    $user = User::factory()->role($role)->create();
    $report = FloodReport::factory()->create();

    // D-23: staff hold validate-report, but rejection is narrowed to the
    // officer's route group, so the ability alone must not admit them.
    $this->withToken(authzToken($user))
        ->postJson("/api/flood-reports/{$report->id}/reject", [
            'rejection_reason' => 'Duplicate report.',
        ])
        ->assertStatus($status);
})->with([
    'officer' => [UserRole::MdrrmoOfficer, 200],
    'staff' => [UserRole::MdrrmoStaff, 403],
    'captain' => [UserRole::BarangayCaptain, 403],
    'tanod' => [UserRole::BarangayTanod, 403],
    'rescue leader' => [UserRole::RescueLeader, 403],
]);

it('restricts overrides to the officer route group', function (UserRole $role, int $status) {
    $user = User::factory()->role($role)->create();
    $report = FloodReport::factory()->create(['status' => FloodReportStatus::Validated]);

    $this->withToken(authzToken($user))
        ->postJson("/api/flood-reports/{$report->id}/override", [
            'to_status' => FloodReportStatus::Returned->value,
            'reason' => 'Field correction required.',
        ])
        ->assertStatus($status);
})->with([
    'officer' => [UserRole::MdrrmoOfficer, 200],
    'staff' => [UserRole::MdrrmoStaff, 403],
    'captain' => [UserRole::BarangayCaptain, 403],
    'tanod' => [UserRole::BarangayTanod, 403],
    'rescue leader' => [UserRole::RescueLeader, 403],
]);

it('restricts user listing to the officer', function (UserRole $role, int $status) {
    $user = User::factory()->role($role)->create();

    $this->withToken(authzToken($user))
        ->getJson('/api/users')
        ->assertStatus($status);
})->with([
    'officer' => [UserRole::MdrrmoOfficer, 200],
    'staff' => [UserRole::MdrrmoStaff, 403],
    'captain' => [UserRole::BarangayCaptain, 403],
    'tanod' => [UserRole::BarangayTanod, 403],
    'rescue leader' => [UserRole::RescueLeader, 403],
]);

it('restricts user creation to the officer', function (UserRole $role, int $status) {
    Notification::fake();

    $user = User::factory()->role($role)->create();

    $this->withToken(authzToken($user))
        ->postJson('/api/users', [
            'first_name' => 'Matrix',
            'last_name' => 'Probe',
            'email' => 'matrix-probe@example.com',
            'contact_number' => '+15550000001',
            'password' => 'Passw0rd1234',
            'role' => UserRole::MdrrmoStaff->value,
        ])
        ->assertStatus($status);
})->with([
    'officer' => [UserRole::MdrrmoOfficer, 201],
    'staff' => [UserRole::MdrrmoStaff, 403],
    'captain' => [UserRole::BarangayCaptain, 403],
    'tanod' => [UserRole::BarangayTanod, 403],
    'rescue leader' => [UserRole::RescueLeader, 403],
]);

it('restricts user updates to the officer', function (UserRole $role, int $status) {
    $user = User::factory()->role($role)->create();
    $target = User::factory()->tanod()->create();

    $this->withToken(authzToken($user))
        ->patchJson("/api/users/{$target->id}", ['first_name' => 'Renamed'])
        ->assertStatus($status);
})->with([
    'officer' => [UserRole::MdrrmoOfficer, 200],
    'staff' => [UserRole::MdrrmoStaff, 403],
    'captain' => [UserRole::BarangayCaptain, 403],
    'tanod' => [UserRole::BarangayTanod, 403],
    'rescue leader' => [UserRole::RescueLeader, 403],
]);

it('restricts user deletions to the officer', function (UserRole $role, int $status) {
    $user = User::factory()->role($role)->create();
    $target = User::factory()->tanod()->create();

    $this->withToken(authzToken($user))
        ->deleteJson("/api/users/{$target->id}")
        ->assertStatus($status);
})->with([
    'officer' => [UserRole::MdrrmoOfficer, 204],
    'staff' => [UserRole::MdrrmoStaff, 403],
    'captain' => [UserRole::BarangayCaptain, 403],
    'tanod' => [UserRole::BarangayTanod, 403],
    'rescue leader' => [UserRole::RescueLeader, 403],
]);

it('reads any report from the command center', function (UserRole $role) {
    $report = FloodReport::factory()->create();
    $user = User::factory()->role($role)->create();

    $this->withToken(authzToken($user))
        ->getJson("/api/flood-reports/{$report->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $report->id);
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
]);

it('confines a barangay role to its own barangay', function (UserRole $role) {
    $own = Barangay::factory()->create();
    $neighbour = Barangay::factory()->create();
    $user = User::factory()->role($role)->forBarangay($own)->create();
    $sameBarangay = FloodReport::factory()->create(['barangay_id' => $own->id]);
    $foreign = FloodReport::factory()->create(['barangay_id' => $neighbour->id]);
    $token = authzToken($user);

    $this->withToken($token)
        ->getJson("/api/flood-reports/{$sameBarangay->id}")
        ->assertOk();

    $this->withToken($token)
        ->getJson("/api/flood-reports/{$foreign->id}")
        ->assertForbidden();
})->with([
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
]);

it('limits a rescue leader to the reports they filed', function () {
    $barangay = Barangay::factory()->create();
    $user = User::factory()->rescueLeader()->forBarangay($barangay)->create();
    $own = FloodReport::factory()->create([
        'user_id' => $user->id,
        'barangay_id' => $barangay->id,
    ]);
    $sameBarangay = FloodReport::factory()->create(['barangay_id' => $barangay->id]);
    $token = authzToken($user);

    $this->withToken($token)
        ->getJson("/api/flood-reports/{$own->id}")
        ->assertOk();

    $this->withToken($token)
        ->getJson("/api/flood-reports/{$sameBarangay->id}")
        ->assertForbidden();
});

it('lists only reports from the caller barangay', function (UserRole $role) {
    $own = Barangay::factory()->create();
    $user = User::factory()->role($role)->forBarangay($own)->create();
    $mine = FloodReport::factory()->count(2)->create(['barangay_id' => $own->id]);
    FloodReport::factory()->create();

    $listed = collect(
        $this->withToken(authzToken($user))->getJson('/api/flood-reports')->assertOk()->json('data'),
    )->pluck('id')->sort()->values()->all();

    expect($listed)->toBe($mine->pluck('id')->sort()->values()->all());
})->with([
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
]);

it('lists every report for the command center', function (UserRole $role) {
    FloodReport::factory()->count(3)->create();
    $user = User::factory()->role($role)->create();

    $this->withToken(authzToken($user))
        ->getJson('/api/flood-reports')
        ->assertOk()
        ->assertJsonCount(3, 'data');
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
]);

it('stamps a submitted report with the caller identity, not the payload', function () {
    Storage::fake('public');
    Http::fake();

    $own = Barangay::factory()->create();
    $foreign = Barangay::factory()->create();
    $otherUser = User::factory()->officer()->create();
    $tanod = User::factory()->tanod()->forBarangay($own)->create();

    $this->withToken(authzToken($tanod))
        ->post('/api/flood-reports', authzFloodReportPayload([
            'user_id' => $otherUser->id,
            'barangay_id' => $foreign->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.user.id', $tanod->id)
        ->assertJsonPath('data.barangay.id', $own->id);
});

it('stamps an impact report with the caller identity, not the payload', function () {
    $own = Barangay::factory()->create();
    $foreign = Barangay::factory()->create();
    $captain = User::factory()->captain()->forBarangay($own)->create();
    $event = FloodEvent::create([
        'title' => 'Tropical storm',
        'starts_at' => now()->subHour(),
    ]);

    $this->withToken(authzToken($captain))
        ->postJson('/api/flood-impact-reports', [
            'event_id' => $event->id,
            'families' => 4,
            'individuals' => 12,
            'user_id' => User::factory()->officer()->create()->id,
            'barangay_id' => $foreign->id,
            'reported_at' => now()->subMinutes(5)->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.user_id', $captain->id)
        ->assertJsonPath('data.barangay_id', $own->id);
});

it('scopes the impact report list to the caller barangay', function (UserRole $role) {
    $own = Barangay::factory()->create();
    $neighbour = Barangay::factory()->create();
    $user = User::factory()->role($role)->forBarangay($own)->create();
    $mine = authzImpactReport($user, $own);
    authzImpactReport(User::factory()->tanod()->forBarangay($own)->create(), $own);
    authzImpactReport(User::factory()->tanod()->forBarangay($neighbour)->create(), $neighbour);

    $listed = collect(
        $this->withToken(authzToken($user))->getJson('/api/flood-impact-reports')->assertOk()->json('data'),
    )->pluck('barangay_id')->unique()->values()->all();

    expect($listed)->toBe([$mine->barangay_id]);
})->with([
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
]);

it('lists every impact report for the command center', function (UserRole $role) {
    $user = User::factory()->role($role)->create();
    authzImpactReport(User::factory()->captain()->create(), Barangay::factory()->create());
    authzImpactReport(User::factory()->captain()->create(), Barangay::factory()->create());

    $this->withToken(authzToken($user))
        ->getJson('/api/flood-impact-reports')
        ->assertOk()
        ->assertJsonCount(2, 'data');
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
]);

it('registers a gate for every declared ability', function () {
    expect(UserAbility::cases())->toHaveCount(12);

    foreach (UserAbility::cases() as $ability) {
        expect(Gate::has($ability->value))->toBeTrue();
    }
});

it('mirrors every role grant through its gate', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    foreach (UserAbility::cases() as $ability) {
        expect(Gate::forUser($user)->allows($ability->value))
            ->toBe($role->hasAbility($ability));
    }
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
    'rescue leader' => UserRole::RescueLeader,
]);

it('answers guests with a json 401 on every protected endpoint', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonStructure(['message']);
})->with([
    ['GET', '/api/user'],
    ['POST', '/api/logout'],
    ['POST', '/api/email/verification-notification'],
    ['GET', '/api/flood-reports'],
    ['GET', '/api/flood-reports/validated-feed'],
    ['POST', '/api/flood-reports'],
    ['GET', '/api/flood-reports/1'],
    ['GET', '/api/flood-impact-reports'],
    ['POST', '/api/flood-impact-reports'],
    ['GET', '/api/geocode/reverse?latitude=14.5995&longitude=120.9842'],
    ['GET', '/api/tokens'],
    ['POST', '/api/tokens'],
    ['DELETE', '/api/tokens'],
    ['DELETE', '/api/tokens/1'],
    ['GET', '/api/realtime/authorization'],
    ['POST', '/api/realtime/latency'],
    ['POST', '/api/flood-reports/1/validate'],
    ['POST', '/api/flood-reports/1/return'],
    ['GET', '/api/barangays'],
    ['GET', '/api/realtime/latency'],
    ['POST', '/api/flood-reports/1/reject'],
    ['POST', '/api/flood-reports/1/override'],
    ['GET', '/api/users'],
    ['POST', '/api/users'],
    ['PATCH', '/api/users/1'],
    ['DELETE', '/api/users/1'],
    ['GET', '/api/roles'],
]);
