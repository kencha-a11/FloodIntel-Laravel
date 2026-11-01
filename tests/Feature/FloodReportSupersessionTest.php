<?php

use App\Enums\AlertLevel;
use App\Enums\FloodReportStatus;
use App\Enums\FloodType;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Storage::fake('public');
    Http::fake();
});

/**
 * Authenticate the next request as the given user.
 *
 * Sanctum caches the resolved user for the test's application instance, so a
 * test that changes actors must forget the guards first (the pattern used by
 * FloodReportValidationTest).
 */
function supersessionActAs(User $user): void
{
    app('auth')->forgetGuards();

    test()->withToken($user->createToken('t')->plainTextToken);
}

function supersessionPayload(array $overrides = []): array
{
    return array_merge([
        'alert_level' => AlertLevel::Alert->value,
        'flood_type' => FloodType::Ponding->value,
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'gps_accuracy' => 12.5,
        'photo' => testPhoto(),
        'notes' => 'Corrected observation.',
        'reported_at' => now()->subMinutes(5)->toIso8601String(),
    ], $overrides);
}

function supersessionReturn(User $officer, FloodReport $report, string $reason): void
{
    supersessionActAs($officer);

    test()->postJson("/api/flood-reports/{$report->id}/return", ['return_reason' => $reason])
        ->assertOk();
}

function supersessionReturnedReport(User $tanod, string $reason = 'Photo is blurry.'): FloodReport
{
    $report = FloodReport::factory()
        ->for($tanod, 'user')
        ->for($tanod->barangay, 'barangay')
        ->create();

    supersessionReturn(User::factory()->officer()->create(), $report, $reason);

    return $report->refresh();
}

it('links a correction to the returned report and leaves its status alone', function () {
    $tanod = User::factory()->tanod()->create();
    $prior = supersessionReturnedReport($tanod);
    supersessionActAs($tanod);

    $response = $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $prior->id]));

    $response->assertCreated()
        ->assertJsonPath('data.status', FloodReportStatus::Submitted->value)
        ->assertJsonPath('data.supersedes_id', $prior->id)
        ->assertJsonPath('data.supersedes.id', $prior->id)
        ->assertJsonPath('data.supersedes.returned_reason', 'Photo is blurry.')
        ->assertJsonPath('data.superseded_by_id', null);

    $prior->refresh();

    expect($prior->status)->toBe(FloodReportStatus::Returned)
        ->and($prior->supersedes_id)->toBeNull()
        ->and($prior->supersededBy?->id)->toBe($response->json('data.id'));
});

it('shows the chain from either side of the link', function () {
    $tanod = User::factory()->tanod()->create();
    $prior = supersessionReturnedReport($tanod);
    supersessionActAs($tanod);

    $correctionId = $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $prior->id]))
        ->assertCreated()
        ->json('data.id');

    $this->getJson("/api/flood-reports/{$correctionId}")
        ->assertOk()
        ->assertJsonPath('data.supersedes.id', $prior->id)
        ->assertJsonPath('data.supersedes.returned_reason', 'Photo is blurry.')
        ->assertJsonPath('data.superseded_by_id', null);

    $this->getJson("/api/flood-reports/{$prior->id}")
        ->assertOk()
        ->assertJsonPath('data.supersedes', null)
        ->assertJsonPath('data.superseded_by_id', $correctionId);
});

it('refuses to correct a report that was not returned', function (string $status) {
    $tanod = User::factory()->tanod()->create();
    $prior = FloodReport::factory()
        ->for($tanod, 'user')
        ->for($tanod->barangay, 'barangay')
        ->create(['status' => $status]);

    supersessionActAs($tanod);

    $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $prior->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('supersedes_id');

    expect(FloodReport::query()->count())->toBe(1);
})->with(['submitted', 'validated', 'rejected']);

it('refuses to correct a report from another barangay', function () {
    $foreignTanod = User::factory()->tanod()->create();
    $foreign = supersessionReturnedReport($foreignTanod);

    $captain = User::factory()->captain()
        ->forBarangay(Barangay::factory()->create())
        ->create();

    supersessionActAs($captain);

    $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $foreign->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('supersedes_id');

    expect($foreign->refresh()->status)->toBe(FloodReportStatus::Returned);
});

it('scopes corrections to the barangay rather than the account', function () {
    $otherTanod = User::factory()->tanod()->create();
    $prior = supersessionReturnedReport($otherTanod);

    $tanod = User::factory()->tanod()->forBarangay($otherTanod->barangay)->create();
    supersessionActAs($tanod);

    $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $prior->id]))
        ->assertCreated()
        ->assertJsonPath('data.supersedes_id', $prior->id)
        ->assertJsonPath('data.barangay.id', $otherTanod->barangay_id)
        ->assertJsonPath('data.user.id', $tanod->id);

    expect($prior->refresh()->status)->toBe(FloodReportStatus::Returned);
});

it('refuses a second correction of the same report', function () {
    $tanod = User::factory()->tanod()->create();
    $prior = supersessionReturnedReport($tanod);
    supersessionActAs($tanod);

    $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $prior->id]))
        ->assertCreated();

    $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $prior->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('supersedes_id');

    expect(FloodReport::query()->count())->toBe(2);
});

it('keeps a chain of corrections and preserves every link', function () {
    $tanod = User::factory()->tanod()->create();
    $officer = User::factory()->officer()->create();

    $first = supersessionReturnedReport($tanod, 'First return.');

    supersessionActAs($tanod);

    $secondId = $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $first->id]))
        ->assertCreated()
        ->json('data.id');

    $second = FloodReport::query()->findOrFail($secondId);
    supersessionReturn($officer, $second, 'Second return.');

    supersessionActAs($tanod);

    $thirdId = $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $secondId]))
        ->assertCreated()
        ->json('data.id');

    $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $first->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('supersedes_id');

    $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $secondId]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('supersedes_id');

    $this->getJson("/api/flood-reports/{$thirdId}")
        ->assertOk()
        ->assertJsonPath('data.supersedes.id', $secondId)
        ->assertJsonPath('data.supersedes.returned_reason', 'Second return.');

    $this->getJson("/api/flood-reports/{$first->id}")
        ->assertOk()
        ->assertJsonPath('data.superseded_by_id', $secondId);

    expect(FloodReport::query()->count())->toBe(3)
        ->and($first->refresh()->status)->toBe(FloodReportStatus::Returned)
        ->and($second->refresh()->status)->toBe(FloodReportStatus::Returned);
});

it('keeps superseded reports in the queue and the export', function () {
    $tanod = User::factory()->tanod()->create();
    $prior = supersessionReturnedReport($tanod);

    supersessionActAs($tanod);

    $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $prior->id]))
        ->assertCreated();

    $officer = User::factory()->officer()->create();
    supersessionActAs($officer);

    $this->getJson('/api/flood-reports')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $response = $this->get('/api/flood-reports/export');

    $response->assertOk()
        ->assertHeader('X-Report-Count', '2');

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    $csv = $response->streamedContent();

    expect($csv)->toContain((string) $prior->id)
        ->and($csv)->toContain(FloodReportStatus::Returned->value);
});

it('offers no route that deletes a report', function () {
    $officer = User::factory()->officer()->create();
    $report = FloodReport::factory()->create();

    supersessionActAs($officer);

    $this->deleteJson("/api/flood-reports/{$report->id}")
        ->assertStatus(405);

    expect(FloodReport::query()->find($report->id))->not->toBeNull();
});

it('refuses to delete a report that a correction points at', function () {
    $tanod = User::factory()->tanod()->create();
    $prior = supersessionReturnedReport($tanod);

    supersessionActAs($tanod);

    $this->post('/api/flood-reports', supersessionPayload(['supersedes_id' => $prior->id]))
        ->assertCreated();

    $prior->refresh();

    expect(fn () => $prior->delete())->toThrow(QueryException::class);

    expect(FloodReport::query()->find($prior->id))->not->toBeNull();
});
