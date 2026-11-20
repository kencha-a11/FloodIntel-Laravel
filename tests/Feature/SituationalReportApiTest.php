<?php

use App\Enums\SitrepSection;
use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\SituationalReport;
use App\Models\SituationalReportSupplement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;

uses(LazilyRefreshDatabase::class);

function sitrepApiAt(string $instant): Carbon
{
    return Carbon::parse($instant, 'Asia/Manila');
}

function sitrepApiToken(User $user): string
{
    // The sanctum guard caches the resolved user for the life of the app
    // instance; switching tokens inside one test must reset it.
    auth()->forgetGuards();

    return $user->createToken('t')->plainTextToken;
}

it('lets staff prepare a frozen revision and refuses field roles', function () {
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $event = FloodEvent::factory()->create();
    $staff = User::factory()->staff()->create();
    $tanod = User::factory()->tanod()->forBarangay(Barangay::factory()->create())->create();

    $this->travelTo(sitrepApiAt('2026-11-02 10:00:00'));

    $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/situational-reports', ['event_id' => $event->id])
        ->assertCreated()
        ->assertJsonPath('data.revision', 1)
        ->assertJsonPath('data.event_id', $event->id)
        ->assertJsonPath('data.format_version', '2');

    $this->withToken(sitrepApiToken($tanod))
        ->postJson('/api/situational-reports', ['event_id' => $event->id])
        ->assertForbidden();

    $this->flushHeaders();
    auth()->forgetGuards();
    $this->postJson('/api/situational-reports')->assertUnauthorized();
});

it('issues per-event report numbers and refuses staff, repeats, and eventless revisions', function () {
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $event = FloodEvent::factory()->create();
    $staff = User::factory()->staff()->create();
    $officer = User::factory()->officer()->create();

    $this->travelTo(sitrepApiAt('2026-11-02 10:00:00'));

    $first = $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/situational-reports', ['event_id' => $event->id])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(sitrepApiToken($staff))
        ->postJson("/api/situational-reports/{$first}/issue")
        ->assertForbidden();

    $this->withToken(sitrepApiToken($officer))
        ->postJson("/api/situational-reports/{$first}/issue", ['office' => ['thru' => 'CONFIRMED THRU NAME']])
        ->assertOk()
        ->assertJsonPath('data.issuance.report_number', 1)
        ->assertJsonPath('data.issuance.issued_by_name', $officer->full_name)
        ->assertJsonPath('data.issuance.office.thru', 'CONFIRMED THRU NAME')
        ->assertJsonPath('data.issuance.office.municipality', 'MUNICIPALITY OF APALIT');

    $this->withToken(sitrepApiToken($officer))
        ->postJson("/api/situational-reports/{$first}/issue")
        ->assertStatus(422);

    $second = $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/situational-reports', ['event_id' => $event->id])
        ->json('data.id');

    $this->withToken(sitrepApiToken($officer))
        ->postJson("/api/situational-reports/{$second}/issue")
        ->assertOk()
        ->assertJsonPath('data.issuance.report_number', 2);

    $eventless = $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/situational-reports')
        ->json('data.id');

    $this->withToken(sitrepApiToken($officer))
        ->postJson("/api/situational-reports/{$eventless}/issue")
        ->assertStatus(422);
});

it('renders the frozen document identically twice and keeps field roles out', function () {
    $barangay = Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $event = FloodEvent::factory()->create(['title' => 'Habagat flooding']);
    $staff = User::factory()->staff()->create();
    $tanod = User::factory()->tanod()->forBarangay($barangay)->create();

    SituationalReportSupplement::factory()->for($event, 'event')->create([
        'section' => SitrepSection::Weather,
        'payload' => ['summary' => 'Moderate to heavy rain over Apalit'],
        'source' => 'PAGASA bulletin 05:00',
    ]);

    $this->travelTo(sitrepApiAt('2026-11-02 10:00:00'));

    $id = $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/situational-reports', ['event_id' => $event->id])
        ->json('data.id');

    $first = $this->withToken(sitrepApiToken($staff))
        ->get("/api/situational-reports/{$id}/document")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->getContent();

    expect($first)->toContain('MUNICIPALITY OF APALIT')
        ->toContain('name="sitrep-document"')
        ->toContain('MEMORANDUM FOR THE CHAIRPERSON, MDRRMC')
        ->toContain('THRU:')
        ->toContain('FROM:')
        ->toContain('SUBJECT:')
        ->toContain('I. SITUATION OVERVIEW')
        ->toContain('II. EFFECTS')
        ->toContain('III. ACTIONS TAKEN')
        ->toContain('iv. Water Supply')
        ->toContain('Depth of Flooding (qualitative tier)')
        ->toContain('No. of Evac. Centers')
        ->toContain('Dead (persons)')
        ->toContain('Injured (persons)')
        ->toContain('Cost of Damages')
        ->toContain('For the information of the Chairperson, MDRRMC')
        ->toContain('Moderate to heavy rain over Apalit')
        ->toContain('Habagat flooding')
        ->toContain('Balucuc')
        ->toContain('Provenance annex')
        // Aggregate-only (D-45): the personal casualty columns and the
        // template's picture attachment line are deliberately absent.
        ->not->toContain('Gender')
        ->not->toContain('Cause of Death')
        ->not->toContain('ATTACH PICTURES');

    $second = $this->withToken(sitrepApiToken($staff))
        ->get("/api/situational-reports/{$id}/document")
        ->getContent();

    expect($second)->toBe($first);

    $this->withToken(sitrepApiToken($tanod))
        ->get("/api/situational-reports/{$id}/document")
        ->assertForbidden();
});

it('renders a version-1 revision from its stage-1 columns with sections unavailable', function () {
    $staff = User::factory()->staff()->create();
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);

    $legacy = SituationalReport::query()->create([
        'window_starts_at' => sitrepApiAt('2026-11-01 15:00:00')->utc(),
        'window_ends_at' => sitrepApiAt('2026-11-02 04:00:00')->utc(),
        'cutoff_at' => sitrepApiAt('2026-11-02 04:00:00')->utc(),
        'cutoff_label' => '3:00 PM – 4:00 AM',
        'timezone' => 'Asia/Manila',
        'snapshot_at' => sitrepApiAt('2026-11-02 04:05:00')->utc(),
        'revision' => 1,
        'format_version' => '1',
        'generated_by_user_id' => $staff->id,
        'generated_by_name' => $staff->full_name,
        'generated_by_role' => $staff->role->value,
        'status_counts' => ['basis' => 'reported_at', 'validated' => 0, 'pending' => 0, 'returned' => 0, 'rejected' => 0],
        'totals' => ['reports' => 0, 'included_reports' => 0, 'alert_level' => null, 'flood_level' => null],
        'barangays' => [],
        'latency' => ['samples' => 0, 'excluded_null_observation' => 0, 'mean_seconds' => null, 'median_seconds' => null, 'max_seconds' => null],
        'stage_two' => ['available' => false, 'reason' => 'legacy', 'affected_families' => null, 'affected_individuals' => null],
    ]);

    $html = $this->withToken(sitrepApiToken($staff))
        ->get("/api/situational-reports/{$legacy->id}/document")
        ->assertOk()
        ->getContent();

    expect($html)->toContain('predates the adapted format')
        ->toContain('Unavailable');
});

it('downloads a DOCX built from the same frozen snapshot', function () {
    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $event = FloodEvent::factory()->create();
    $staff = User::factory()->staff()->create();

    $this->travelTo(sitrepApiAt('2026-11-02 10:00:00'));

    $id = $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/situational-reports', ['event_id' => $event->id])
        ->json('data.id');

    $response = $this->withToken(sitrepApiToken($staff))
        ->get("/api/situational-reports/{$id}/docx")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    expect($response->streamedContent())->toStartWith('PK');
});

it('rejects personal fields in supplement payloads at the input boundary', function () {
    $barangay = Barangay::factory()->create();
    $event = FloodEvent::factory()->create();
    $staff = User::factory()->staff()->create();

    $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/sitrep-supplements', [
            'event_id' => $event->id,
            'section' => 'casualties',
            'barangay_id' => $barangay->id,
            'payload' => ['dead' => 1, 'injured' => 2, 'casualties' => [['full_name' => 'Juan Dela Cruz', 'age' => 44]]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payload');

    $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/sitrep-supplements', [
            'event_id' => $event->id,
            'section' => 'casualties',
            'barangay_id' => $barangay->id,
            'payload' => ['dead' => 1, 'injured' => 2],
            'source' => 'MDRRMO assessment',
        ])
        ->assertCreated();

    expect(SituationalReportSupplement::query()->count())->toBe(1);
});

it('requires a barangay for barangay-scoped sections and supports draft edits', function () {
    $barangay = Barangay::factory()->create();
    $event = FloodEvent::factory()->create();
    $staff = User::factory()->staff()->create();
    $tanod = User::factory()->tanod()->forBarangay($barangay)->create();

    $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/sitrep-supplements', [
            'event_id' => $event->id,
            'section' => 'damaged_houses',
            'payload' => ['partial' => 3, 'total' => 1],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('barangay_id');

    $id = $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/sitrep-supplements', [
            'event_id' => $event->id,
            'section' => 'damaged_houses',
            'barangay_id' => $barangay->id,
            'payload' => ['partial' => 3, 'total' => 1],
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(sitrepApiToken($staff))
        ->patchJson("/api/sitrep-supplements/{$id}", [
            'section' => 'damaged_houses',
            'barangay_id' => $barangay->id,
            'payload' => ['partial' => 5, 'total' => 1],
        ])
        ->assertOk()
        ->assertJsonPath('data.payload.partial', 5);

    $this->withToken(sitrepApiToken($tanod))
        ->patchJson("/api/sitrep-supplements/{$id}", [
            'section' => 'damaged_houses',
            'barangay_id' => $barangay->id,
            'payload' => ['partial' => 9, 'total' => 9],
        ])
        ->assertForbidden();

    $this->withToken(sitrepApiToken($staff))
        ->deleteJson("/api/sitrep-supplements/{$id}")
        ->assertNoContent();

    expect(SituationalReportSupplement::query()->count())->toBe(0);
});
