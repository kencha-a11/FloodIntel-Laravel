<?php

use App\Enums\AccessTagType;
use App\Enums\FloodLevel;
use App\Enums\UserRole;
use App\Models\AccessTag;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function tagPayload(array $overrides = []): array
{
    $payload = array_merge([
        'type' => AccessTagType::NarrowAlley->value,
        'landmark' => 'Sitio Balite footpath',
        'latitude' => 14.9512345,
        'longitude' => 120.8312345,
        'gps_accuracy' => 6.5,
        'address' => 'Purok 3, Kapitangan',
        'observed_at' => now()->subMinutes(10)->toIso8601String(),
    ], $overrides);

    // Allow removing a key by setting it to null in overrides
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($payload[$key]);
        }
    }

    return $payload;
}

/**
 * @return array<int, int>
 */
function tagIds(AccessTag ...$tags): array
{
    return collect($tags)->pluck('id')->sort()->values()->all();
}

it('stores a tag as a place record with its capture instant', function () {
    $observing = now()->subMinutes(15);

    $tag = AccessTag::factory()->create([
        'type' => AccessTagType::NarrowAlley,
        'observed_at' => $observing,
        'latitude' => 14.9500000,
        'longitude' => 120.8300000,
        'gps_accuracy' => 8.5,
    ]);

    $stored = AccessTag::findOrFail($tag->id);

    expect($stored->type)->toBe(AccessTagType::NarrowAlley)
        ->and($stored->observed_at->toDateTimeString())->toBe($observing->toDateTimeString())
        ->and($stored->is_active)->toBeTrue()
        ->and($stored->barangay)->toBeInstanceOf(Barangay::class)
        ->and($stored->createdBy)->toBeInstanceOf(User::class);
});

it('keeps the recorded account and barangay as the tag owner', function () {
    $barangay = Barangay::factory()->create();
    $tanod = User::factory()->tanod()->forBarangay($barangay)->create();

    $tag = AccessTag::factory()->create([
        'barangay_id' => $barangay->id,
        'created_by' => $tanod->id,
    ]);

    expect($tag->barangay_id)->toBe($barangay->id)
        ->and($tag->created_by)->toBe($tanod->id);
});

it('scopes reads to every barangay for the command center', function (UserRole $role) {
    $user = User::factory()->role($role)->create();
    AccessTag::factory()->count(2)->create();

    expect(AccessTag::visibleTo($user)->count())->toBe(2);
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
]);

it('scopes reads to its own barangay for a field account', function (UserRole $role) {
    $barangay = Barangay::factory()->create();
    $user = User::factory()->role($role)->forBarangay($barangay)->create();
    AccessTag::factory()->create(['barangay_id' => $barangay->id]);
    AccessTag::factory()->create();

    $visible = AccessTag::visibleTo($user)->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->barangay_id)->toBe($barangay->id);
})->with([
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
]);

it('scopes reads to its own records for an account with no wider right', function () {
    $rescue = User::factory()->rescueLeader()->create();
    AccessTag::factory()->create(['created_by' => $rescue->id]);
    AccessTag::factory()->create();

    $visible = AccessTag::visibleTo($rescue)->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->created_by)->toBe($rescue->id);
});

it('records a constraint the closed set does not name only with a note', function () {
    $tag = AccessTag::factory()->other()->create();

    expect($tag->type)->toBe(AccessTagType::Other)
        ->and($tag->type->requiresNote())->toBeTrue()
        ->and($tag->note)->not->toBeEmpty();
});

it('records a tag for the caller barangay and account, never the payload', function (UserRole $role) {
    $home = Barangay::factory()->create(['name' => 'Kapitangan']);
    $elsewhere = Barangay::factory()->create(['name' => 'Sucad']);
    $recorder = User::factory()->role($role)->forBarangay($home)->create();
    $other = User::factory()->tanod()->forBarangay($elsewhere)->create();
    $observed = now()->subMinutes(10);

    $created = $this->withToken($recorder->createToken('t')->plainTextToken)
        ->postJson('/api/access-tags', tagPayload([
            'observed_at' => $observed->toIso8601String(),
            'barangay_id' => $elsewhere->id,
            'created_by' => $other->id,
            'is_active' => false,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.barangay.id', $home->id)
        ->assertJsonPath('data.recorded_by.id', $recorder->id)
        ->assertJsonPath('data.type', 'narrow_alley')
        ->assertJsonPath('data.type_label', 'Narrow alley — foot carry only')
        ->assertJsonPath('data.is_active', true);

    $stored = AccessTag::findOrFail($created->json('data.id'));

    expect($stored->barangay_id)->toBe($home->id)
        ->and($stored->created_by)->toBe($recorder->id)
        ->and($stored->is_active)->toBeTrue()
        ->and($stored->observed_at->timestamp)->toBe($observed->timestamp)
        ->and($stored->created_at->timestamp)->toBeGreaterThan($stored->observed_at->timestamp);
})->with([
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
]);

it('refuses tag recording to a command-center or rescue account', function (UserRole $role) {
    $account = User::factory()->role($role)->create();

    $this->withToken($account->createToken('t')->plainTextToken)
        ->postJson('/api/access-tags', tagPayload())
        ->assertForbidden();

    expect(AccessTag::count())->toBe(0);
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
    'rescue leader' => UserRole::RescueLeader,
]);

it('refuses a tag that does not carry what FR-114 requires', function (array $overrides, string $field) {
    $barangay = Barangay::factory()->create();
    $tanod = User::factory()->tanod()->forBarangay($barangay)->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->postJson('/api/access-tags', tagPayload($overrides))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(AccessTag::count())->toBe(0);
})->with([
    'an unlisted type' => [['type' => 'flooded_bridge'], 'type'],
    'a missing type' => [['type' => null], 'type'],
    'other without a note' => [['type' => AccessTagType::Other->value], 'note'],
    'a note over the cap' => [['type' => AccessTagType::Other->value, 'note' => str_repeat('a', 1001)], 'note'],
    'a landmark over the cap' => [['landmark' => str_repeat('a', 256)], 'landmark'],
    'a missing latitude' => [['latitude' => null], 'latitude'],
    'a latitude off the globe' => [['latitude' => 91], 'latitude'],
    'a longitude off the globe' => [['longitude' => 181], 'longitude'],
    'a negative accuracy' => [['gps_accuracy' => -1], 'gps_accuracy'],
    'a missing observation instant' => [['observed_at' => null], 'observed_at'],
    'an observation instant in the future' => [['observed_at' => now()->addMinute()->toIso8601String()], 'observed_at'],
]);

it('refuses a tag from an account with no barangay before any row is written', function () {
    $tanod = User::factory()->tanod()->create(['barangay_id' => null]);

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->postJson('/api/access-tags', tagPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('barangay_id');

    expect(AccessTag::count())->toBe(0);
});

it('neither lists nor opens a tag outside the caller scope', function (UserRole $role) {
    $home = Barangay::factory()->create();
    $elsewhere = Barangay::factory()->create();
    $recorder = User::factory()->tanod()->forBarangay($home)->create();
    $tag = AccessTag::factory()->create([
        'barangay_id' => $home->id,
        'created_by' => $recorder->id,
    ]);
    $outsider = User::factory()->role($role)->create(['barangay_id' => $elsewhere->id]);
    $token = $outsider->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/access-tags')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withToken($token)
        ->getJson('/api/access-tags/'.$tag->id)
        ->assertForbidden();
})->with([
    'a tanod of another barangay' => UserRole::BarangayTanod,
    'a captain of another barangay' => UserRole::BarangayCaptain,
    'a rescue leader' => UserRole::RescueLeader,
]);

it('lists tags across barangays for the command center', function (UserRole $role) {
    $account = User::factory()->role($role)->create();
    AccessTag::factory()->create();
    AccessTag::factory()->create();

    $this->withToken($account->createToken('t')->plainTextToken)
        ->getJson('/api/access-tags')
        ->assertOk()
        ->assertJsonCount(2, 'data');
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
]);

it('narrows the list with each of the four filters', function () {
    $alpha = Barangay::factory()->create(['name' => 'Kapitangan']);
    $beta = Barangay::factory()->create(['name' => 'Sucad']);
    $recorder = User::factory()->tanod()->forBarangay($alpha)->create();
    $officer = User::factory()->officer()->create();
    $token = $officer->createToken('t')->plainTextToken;

    $twoDaysAgo = now()->subDays(2)->startOfHour();
    $yesterday = now()->subDay()->startOfHour();

    $alley = AccessTag::factory()->create([
        'barangay_id' => $alpha->id,
        'created_by' => $recorder->id,
        'type' => AccessTagType::NarrowAlley,
        'observed_at' => $twoDaysAgo,
        'is_active' => true,
    ]);
    $stairs = AccessTag::factory()->create([
        'barangay_id' => $alpha->id,
        'created_by' => $recorder->id,
        'type' => AccessTagType::StairsOrSteep,
        'observed_at' => $yesterday,
        'is_active' => false,
    ]);
    $noVehicle = AccessTag::factory()->create([
        'barangay_id' => $beta->id,
        'created_by' => $recorder->id,
        'type' => AccessTagType::NoVehicleAccess,
        'observed_at' => now(),
        'is_active' => true,
    ]);

    $list = function (array $query) use ($token): array {
        return collect(
            $this->withToken($token)
                ->getJson('/api/access-tags?'.http_build_query($query))
                ->assertOk()
                ->json('data'),
        )->pluck('id')->sort()->values()->all();
    };

    expect($list([]))->toBe(tagIds($alley, $stairs, $noVehicle))
        ->and($list(['barangay_id' => $beta->id]))->toBe(tagIds($noVehicle))
        ->and($list(['type' => AccessTagType::StairsOrSteep->value]))->toBe(tagIds($stairs))
        ->and($list(['is_active' => 1]))->toBe(tagIds($alley, $noVehicle))
        ->and($list(['is_active' => 0]))->toBe(tagIds($stairs))
        ->and($list(['from' => $yesterday->copy()->startOfDay()->toDateTimeString()]))->toBe(tagIds($stairs, $noVehicle))
        ->and($list(['to' => $yesterday->copy()->endOfDay()->toDateTimeString()]))->toBe(tagIds($alley, $stairs))
        ->and($list([
            'from' => $yesterday->copy()->startOfDay()->toDateTimeString(),
            'to' => $yesterday->copy()->endOfDay()->toDateTimeString(),
        ]))->toBe(tagIds($stairs));
});

it('refuses a filter it cannot answer', function (string $query, string $field) {
    $officer = User::factory()->officer()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/access-tags?'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'an unlisted type' => ['type=flooded_bridge', 'type'],
    'a barangay that does not exist' => ['barangay_id=99999', 'barangay_id'],
    'a window that runs backwards' => ['from=2026-11-02&to=2026-11-01', 'to'],
]);

it('cannot widen its read with a barangay filter when it may not read beyond its own', function (UserRole $role) {
    $own = Barangay::factory()->create();
    $elsewhere = Barangay::factory()->create();
    $account = User::factory()->role($role)->forBarangay($own)->create();
    $mine = AccessTag::factory()->create(['barangay_id' => $own->id]);
    $foreign = AccessTag::factory()->create(['barangay_id' => $elsewhere->id]);

    $this->withToken($account->createToken('t')->plainTextToken)
        ->getJson('/api/access-tags?barangay_id='.$foreign->barangay_id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
})->with([
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
]);

it('returns the stable type value with its label and no other enum form', function () {
    $officer = User::factory()->officer()->create();
    $tag = AccessTag::factory()->create(['type' => AccessTagType::StructurallyBlocked]);
    $token = $officer->createToken('t')->plainTextToken;

    $listed = $this->withToken($token)->getJson('/api/access-tags')->assertOk()->json('data.0');
    $shown = $this->withToken($token)->getJson('/api/access-tags/'.$tag->id)->assertOk()->json('data');

    foreach ([$listed, $shown] as $payload) {
        expect($payload['type'])->toBe('structurally_blocked')
            ->and($payload['type_label'])->toBe('Structurally blocked')
            ->and(json_encode($payload))->not->toContain('StructurallyBlocked');
    }
});

it('publishes the closed taxonomy with a label and its note rule', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $data = $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/access-tags/types')
        ->assertOk()
        ->json('data');

    expect(array_column($data, 'value'))
        ->toBe(array_map(fn (AccessTagType $type): string => $type->value, AccessTagType::cases()));

    foreach (AccessTagType::cases() as $index => $type) {
        expect($data[$index])->toMatchArray([
            'value' => $type->value,
            'label' => $type->label(),
            'requires_note' => $type->requiresNote(),
        ]);
    }

    expect(array_column(array_filter($data, fn (array $option): bool => $option['requires_note']), 'value'))
        ->toBe([AccessTagType::Other->value]);
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
    'rescue leader' => UserRole::RescueLeader,
]);

it('publishes the vocabulary and not a single tag', function () {
    $barangay = Barangay::factory()->create();
    $tanod = User::factory()->tanod()->forBarangay($barangay)->create();
    AccessTag::factory()->create([
        'barangay_id' => $barangay->id,
        'created_by' => $tanod->id,
    ]);

    $data = $this->withToken($tanod->createToken('t')->plainTextToken)
        ->getJson('/api/access-tags/types')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(count(AccessTagType::cases()));

    foreach ($data as $option) {
        expect(array_keys($option))->toBe(['value', 'label', 'requires_note']);
    }
});

it('retires a tag in place, keeping the recorded observation', function (UserRole $role) {
    $barangay = Barangay::factory()->create();
    $recorder = User::factory()->role($role)->forBarangay($barangay)->create();
    $observed = now()->subHours(2)->startOfSecond();
    $tag = AccessTag::factory()->create([
        'barangay_id' => $barangay->id,
        'created_by' => $recorder->id,
        'type' => AccessTagType::NarrowAlley,
        'observed_at' => $observed,
        'latitude' => 14.9500000,
        'longitude' => 120.8300000,
        'is_active' => true,
    ]);

    $this->withToken($recorder->createToken('t')->plainTextToken)
        ->postJson('/api/access-tags/'.$tag->id.'/retire', ['reason' => 'Footpath reopened.'])
        ->assertOk()
        ->assertJsonPath('data.id', $tag->id)
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.retired_reason', 'Footpath reopened.')
        ->assertJsonPath('data.retired_by.id', $recorder->id)
        ->assertJsonPath('data.retired_by.role', $role->value);

    $stored = AccessTag::findOrFail($tag->id);

    expect(AccessTag::count())->toBe(1)
        ->and($stored->is_active)->toBeFalse()
        ->and($stored->retired_at)->not->toBeNull()
        ->and($stored->retired_reason)->toBe('Footpath reopened.')
        ->and($stored->retired_by)->toBe($recorder->id)
        ->and($stored->type)->toBe(AccessTagType::NarrowAlley)
        ->and($stored->observed_at->timestamp)->toBe($observed->timestamp)
        ->and($stored->latitude)->toBe('14.9500000')
        ->and($stored->longitude)->toBe('120.8300000');
})->with([
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
]);

it('retires without a reason and refuses one past the cap', function (array $body, ?string $error) {
    $barangay = Barangay::factory()->create();
    $tanod = User::factory()->tanod()->forBarangay($barangay)->create();
    $tag = AccessTag::factory()->create(['barangay_id' => $barangay->id, 'created_by' => $tanod->id]);
    $token = $tanod->createToken('t')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/access-tags/'.$tag->id.'/retire', $body);

    if ($error === null) {
        $response->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.retired_reason', $body['reason'] ?? null);
    } else {
        $response->assertStatus(422)->assertJsonValidationErrors($error);

        expect($tag->refresh()->is_active)->toBeTrue();
    }
})->with([
    'no body at all' => [[], null],
    'a reason at the cap' => [['reason' => str_repeat('a', 255)], null],
    'a reason past the cap' => [['reason' => str_repeat('a', 256)], 'reason'],
    'a reason that is not text' => [['reason' => ['reopened']], 'reason'],
]);

it('answers a second retirement with the first, never the retry', function () {
    $barangay = Barangay::factory()->create();
    $recorder = User::factory()->tanod()->forBarangay($barangay)->create();
    $officer = User::factory()->officer()->create();
    $tag = AccessTag::factory()->create(['barangay_id' => $barangay->id, 'created_by' => $recorder->id]);
    $token = $recorder->createToken('t')->plainTextToken;

    $first = $this->withToken($token)
        ->postJson('/api/access-tags/'.$tag->id.'/retire', ['reason' => 'Footpath reopened.'])
        ->assertOk()
        ->json('data');

    $this->travel(1)->hours();

    $second = $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson('/api/access-tags/'.$tag->id.'/retire', ['reason' => 'Second attempt.'])
        ->assertOk()
        ->json('data');

    expect($second['retired_at'])->toBe($first['retired_at'])
        ->and($second['retired_reason'])->toBe('Footpath reopened.')
        ->and($second['retired_by']['id'])->toBe($recorder->id)
        ->and($second['is_active'])->toBeFalse();
});

it('refuses retirement to an account that neither recorded the tag nor manages users', function (UserRole $role, bool $sameBarangay) {
    $home = Barangay::factory()->create();
    $recorder = User::factory()->tanod()->forBarangay($home)->create();
    $outsider = User::factory()->role($role)->create([
        'barangay_id' => $sameBarangay ? $home->id : Barangay::factory()->create()->id,
    ]);
    $tag = AccessTag::factory()->create(['barangay_id' => $home->id, 'created_by' => $recorder->id]);

    $this->withToken($outsider->createToken('t')->plainTextToken)
        ->postJson('/api/access-tags/'.$tag->id.'/retire', ['reason' => 'Not mine.'])
        ->assertForbidden();

    $stored = AccessTag::findOrFail($tag->id);

    expect($stored->is_active)->toBeTrue()
        ->and($stored->retired_at)->toBeNull();
})->with([
    'a tanod of another barangay' => [UserRole::BarangayTanod, false],
    'a captain of another barangay' => [UserRole::BarangayCaptain, false],
    'a tanod of the same barangay' => [UserRole::BarangayTanod, true],
    'a captain of the same barangay' => [UserRole::BarangayCaptain, true],
    'a command-center staff account' => [UserRole::MdrrmoStaff, false],
    'a rescue leader' => [UserRole::RescueLeader, false],
]);

it('lets a managing officer retire a tag it did not record and names that officer', function () {
    $barangay = Barangay::factory()->create();
    $recorder = User::factory()->tanod()->forBarangay($barangay)->create();
    $officer = User::factory()->officer()->create();
    $tag = AccessTag::factory()->create(['barangay_id' => $barangay->id, 'created_by' => $recorder->id]);

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson('/api/access-tags/'.$tag->id.'/retire', ['reason' => 'Route reclassified.'])
        ->assertOk()
        ->assertJsonPath('data.recorded_by.id', $recorder->id)
        ->assertJsonPath('data.retired_by.id', $officer->id)
        ->assertJsonPath('data.retired_by.role', UserRole::MdrrmoOfficer->value);
});

it('lets the list separate retired tags from active ones', function () {
    $barangay = Barangay::factory()->create();
    $recorder = User::factory()->tanod()->forBarangay($barangay)->create();
    $officer = User::factory()->officer()->create();
    $active = AccessTag::factory()->create(['barangay_id' => $barangay->id, 'created_by' => $recorder->id]);
    $retired = AccessTag::factory()->create(['barangay_id' => $barangay->id, 'created_by' => $recorder->id]);
    $token = $officer->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/access-tags/'.$retired->id.'/retire', ['reason' => 'Footpath reopened.'])
        ->assertOk();

    $listed = function (array $query) use ($token): array {
        return collect(
            $this->withToken($token)
                ->getJson('/api/access-tags?'.http_build_query($query))
                ->assertOk()
                ->json('data'),
        )->pluck('id')->sort()->values()->all();
    };

    $retiredOnly = $this->withToken($token)
        ->getJson('/api/access-tags?is_active=0')
        ->assertOk()
        ->json('data');

    expect($listed(['is_active' => 1]))->toBe([$active->id])
        ->and($listed(['is_active' => 0]))->toBe([$retired->id])
        ->and($retiredOnly[0]['retired_at'])->not->toBeNull()
        ->and($retiredOnly[0]['retired_reason'])->toBe('Footpath reopened.');
});

it('offers no route that deletes a tag', function () {
    $barangay = Barangay::factory()->create();
    $tanod = User::factory()->tanod()->forBarangay($barangay)->create();
    $tag = AccessTag::factory()->create(['barangay_id' => $barangay->id, 'created_by' => $tanod->id]);

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->deleteJson('/api/access-tags/'.$tag->id)
        ->assertStatus(405);

    expect(AccessTag::count())->toBe(1);
});

it('keeps the observation immutable and a retirement final at the model', function () {
    $tag = AccessTag::factory()->create(['observed_at' => now()->subHour()]);

    expect(fn () => $tag->update(['observed_at' => now()->subMinute()]))
        ->toThrow(LogicException::class, 'The recorded observation is immutable.');

    expect(fn () => $tag->update(['latitude' => 14.1]))
        ->toThrow(LogicException::class, 'The recorded observation is immutable.');

    $tag = $tag->fresh();
    $recorder = User::findOrFail($tag->created_by);
    $tag->retire($recorder, 'Footpath reopened.');

    $retired = $tag->fresh();

    expect($retired->is_active)->toBeFalse();

    expect(fn () => $retired->update(['retired_reason' => 'Rewritten.']))
        ->toThrow(LogicException::class, 'A tag is retired once.');

    expect(fn () => $retired->update(['is_active' => true]))
        ->toThrow(LogicException::class, 'A tag is retired once.');

    expect($tag->fresh()->retired_reason)->toBe('Footpath reopened.');
});

it('leaves a second model retirement as a no-op that keeps the recorded event', function () {
    $tag = AccessTag::factory()->create();
    $recorder = User::findOrFail($tag->created_by);
    $officer = User::factory()->officer()->create();

    $tag->retire($recorder, 'First.');

    $first = $tag->fresh();
    $stamp = $first->retired_at;

    $this->travel(1)->hours();

    expect($first->retire($officer, 'Second.')->retired_reason)->toBe('First.')
        ->and($first->retired_at->timestamp)->toBe($stamp->timestamp)
        ->and($first->retired_by)->toBe($recorder->id);
});

it('leaves the level-report path untouched', function () {
    Storage::fake('public');
    Http::fake();

    $barangay = Barangay::factory()->create();
    $tanod = User::factory()->tanod()->forBarangay($barangay)->create();
    $token = $tanod->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/access-tags', tagPayload())
        ->assertCreated();

    expect(FloodReport::count())->toBe(0);

    $this->withToken($token)
        ->post('/api/flood-reports', [
            'flood_level' => FloodLevel::Knee->value,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'gps_accuracy' => 12.5,
            'photo' => testPhoto(),
            'notes' => 'Water rising near the school.',
            'reported_at' => now()->subMinutes(5)->toIso8601String(),
        ])
        ->assertCreated();

    expect(FloodReport::count())->toBe(1)
        ->and(AccessTag::count())->toBe(1);
});
