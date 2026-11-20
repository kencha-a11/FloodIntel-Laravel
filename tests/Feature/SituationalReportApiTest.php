<?php

use App\Enums\SitrepSection;
use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\SituationalReport;
use App\Models\SituationalReportSupplement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

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
        ->toContain('data:image/png;base64')
        ->toContain('Email:')
        ->toContain('MEMORANDUM FOR THE CHAIRPERSON, MDRRMC')
        ->toContain('text-decoration: underline')
        ->toContain('>THRU</span>')
        ->toContain('>FROM</span>')
        ->toContain('>SUBJECT</span>')
        ->toContain('memo-colon')
        ->not->toContain('For the period')
        ->toContain('band-roman')
        ->toContain('band-letter')
        ->toContain('#bdd6ee')
        ->toContain('I. SITUATION OVERVIEW')
        ->toContain('II. EFFECTS')
        ->toContain('III. ACTIONS TAKEN')
        ->toContain('iv. Water Supply')
        ->toContain('Depth of Flooding')
        ->toContain('c. Status of Lifelines (Source/s)')
        ->toContain('11/02/2026, as of')
        ->toContain('- Crops')
        ->toContain('colspan="3" class="center">Affected Population')
        ->not->toContain('>Total<')
        ->not->toContain('CARLOS, Municipal')
        ->not->toContain('band-sub">Evacuees')
        ->not->toContain('band-sub">Affected Population')
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

it('reproduces the frozen MDRRMO reference specimen', function () {
    $reference = json_decode(
        file_get_contents(base_path('tests/Fixtures/sitrep-mdrrmo-reference.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $event = FloodEvent::factory()->create();
    $staff = User::factory()->staff()->create();

    $this->travelTo(sitrepApiAt('2026-11-02 10:00:00'));

    $id = $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/situational-reports', ['event_id' => $event->id])
        ->json('data.id');

    $html = $this->withToken(sitrepApiToken($staff))
        ->get("/api/situational-reports/{$id}/document")
        ->assertOk()
        ->getContent();

    foreach ($reference['required'] as $needle) {
        expect($html)->toContain($needle);
    }

    foreach ($reference['forbidden'] as $needle) {
        expect($html)->not->toContain($needle);
    }

    foreach ($reference['band_colors'] as $color) {
        expect($html)->toContain($color);
    }
});

it('reproduces every static skeleton line of the shipped office reference', function () {
    // The office's own HTML specimen ships with the system (scripts removed).
    // Parse it here and demand that the generated document carries each of its
    // static lines, so the reference itself — not a hand-copied list — is the
    // authority the suite checks against.
    $referencePath = resource_path('sitrep/reference/mdrrmo-sitrep-template.html');
    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/sitrep-mdrrmo-reference.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // Hash LF-normalized bytes: .gitattributes checks the reference out with
    // LF, while the received copy carried CRLF.
    $shipped = str_replace("\r\n", "\n", file_get_contents($referencePath));
    expect(hash_equals($fixture['shipped_sha256'], hash('sha256', $shipped)))->toBeTrue();

    preg_match_all('/<div class="t [^"]*">(.*?)<\/div>/s', file_get_contents($referencePath), $matches);

    // Placeholders, example data, personal casualty columns and the picture
    // instruction are not skeleton: the generator fills or lawfully omits them.
    $skip = ['XX', 'MM/DD', 'n/a', 'Sulipan', 'JUAN', 'Southwest Monsoon', 'ATTACH', 'Name', 'Gender', 'Age', 'Address', 'Cause', 'Date /', 'Source/s:', 'AM/NN/PM'];

    Barangay::factory()->create(['name' => 'Balucuc', 'code' => 'PH0305402001']);
    $event = FloodEvent::factory()->create();
    $staff = User::factory()->staff()->create();

    $this->travelTo(sitrepApiAt('2026-11-02 10:00:00'));

    $id = $this->withToken(sitrepApiToken($staff))
        ->postJson('/api/situational-reports', ['event_id' => $event->id])
        ->json('data.id');

    $html = $this->withToken(sitrepApiToken($staff))
        ->get("/api/situational-reports/{$id}/document")
        ->assertOk()
        ->getContent();

    // Compare text, not markup: the reference packs each memorandum row into
    // one text run, while the preview splits label/colon/value across spans.
    $text = preg_replace('/<(style|script)\b.*?<\/\1>/s', '', $html);
    $flat = preg_replace('/\s+/', '', html_entity_decode(strip_tags($text)));
    $checked = 0;

    foreach ($matches[1] as $raw) {
        $line = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($raw))));

        if ($line === '' || in_array($line, ['iv.', '1.'], true)) {
            continue;
        }

        foreach ($skip as $marker) {
            if (str_contains($line, $marker)) {
                continue 2;
            }
        }

        expect($flat)->toContain(preg_replace('/\s+/', '', $line));
        $checked++;
    }

    expect($checked)->toBeGreaterThan(60);
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

    $package = $response->streamedContent();
    expect($package)->toStartWith('PK');

    // The memorandum must carry the template's tab stops (colon at 1418
    // twips, value at 2268 with a hanging indent) and no period line; the
    // window stays in the provenance annex only.
    $file = tempnam(sys_get_temp_dir(), 'sitrep_docx_');
    file_put_contents($file, $package);
    $dir = $file.'_x';
    $archive = new PclZip($file);
    expect($archive->extract(PCLZIP_OPT_PATH, $dir))->not->toBe(0);
    $xml = file_get_contents($dir.'/word/document.xml');
    File::deleteDirectory($dir);
    unlink($file);

    $tabs = json_decode(
        file_get_contents(base_path('tests/Fixtures/sitrep-mdrrmo-reference.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['memorandum_tab_twips'];

    expect($xml)->toContain('w:pos="'.$tabs['colon'].'"')
        ->toContain('w:pos="'.$tabs['value'].'"')
        ->toContain('w:hanging="'.$tabs['hanging'].'"')
        ->toContain('>THRU<')
        ->toContain('- Crops')
        ->toContain('as of')
        ->not->toContain('<w:t>Total</w:t>')
        ->not->toContain('For the period');
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
