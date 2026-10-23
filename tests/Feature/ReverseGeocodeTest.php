<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function geocodeResponse(): array
{
    return [
        'display_name' => '17, Mabini Street, Barangay 5, Tacloban City, Leyte, 6500, Philippines',
        'address' => [
            'house_number' => '17',
            'road' => 'Mabini Street',
            'neighbourhood' => 'Barangay 5',
            'city' => 'Tacloban City',
            'state' => 'Leyte',
            'postcode' => '6500',
            'country' => 'Philippines',
        ],
    ];
}

it('requires authentication', function () {
    $this->getJson('/api/geocode/reverse?latitude=14.5995&longitude=120.9842')
        ->assertUnauthorized();
});

it('requires a verified user', function () {
    $tanod = User::factory()->tanod()->unverified()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->getJson('/api/geocode/reverse?latitude=14.5995&longitude=120.9842')
        ->assertForbidden();
});

it('validates the coordinates', function () {
    $tanod = User::factory()->tanod()->create();
    $token = $tanod->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/geocode/reverse?latitude=200&longitude=120.9842')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude']);

    $this->withToken($token)
        ->getJson('/api/geocode/reverse?latitude=14.5995&longitude=-300')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['longitude']);
});

it('returns a formatted address', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(geocodeResponse()),
    ]);

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->getJson('/api/geocode/reverse?latitude=14.5995&longitude=120.9842')
        ->assertOk()
        ->assertJsonPath(
            'data.address',
            '17, Mabini Street, Barangay 5, Tacloban City, Leyte, 6500, Philippines',
        );

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/reverse')
        && $request->hasHeader('User-Agent'));
});

it('falls back to the display name when components are missing', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '  Mabini   Street, Tacloban City, Philippines ',
        ]),
    ]);

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->getJson('/api/geocode/reverse?latitude=14.5995&longitude=120.9842')
        ->assertOk()
        ->assertJsonPath(
            'data.address',
            'Mabini Street, Tacloban City, Philippines',
        );
});

it('caches addresses for nearby coordinates', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(geocodeResponse()),
    ]);

    $tanod = User::factory()->tanod()->create();
    $token = $tanod->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/geocode/reverse?latitude=14.5995&longitude=120.9842')
        ->assertOk();

    $this->withToken($token)
        ->getJson('/api/geocode/reverse?latitude=14.5995001&longitude=120.9842001')
        ->assertOk();

    Http::assertSentCount(1);
});

it('returns null when the provider fails', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([], 503),
    ]);

    $tanod = User::factory()->tanod()->create();

    $this->withToken($tanod->createToken('t')->plainTextToken)
        ->getJson('/api/geocode/reverse?latitude=14.5995&longitude=120.9842')
        ->assertOk()
        ->assertJsonPath('data.address', null);
});
