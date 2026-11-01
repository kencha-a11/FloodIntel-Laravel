<?php

use App\Models\FloodReport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['services.geocoder.min_interval_ms' => 0]);
});

function resolvedAddress(): string
{
    return 'Mabini Street, Apalit, Pampanga, Philippines';
}

it('fills every missing address and leaves existing ones alone', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(['display_name' => resolvedAddress()]),
    ]);

    $first = FloodReport::factory()->create([
        'latitude' => 14.9501,
        'longitude' => 120.7501,
        'address' => null,
    ]);
    $second = FloodReport::factory()->create([
        'latitude' => 14.9502,
        'longitude' => 120.7502,
        'address' => null,
    ]);
    $kept = FloodReport::factory()->create(['address' => 'Existing address']);

    $this->artisan('reports:resolve-addresses')->assertSuccessful();

    expect($first->fresh()->address)->toBe(resolvedAddress())
        ->and($second->fresh()->address)->toBe(resolvedAddress())
        ->and($kept->fresh()->address)->toBe('Existing address');

    Http::assertSentCount(2);
});

it('leaves the address empty when the provider fails', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([], 503),
    ]);

    $report = FloodReport::factory()->create([
        'latitude' => 14.9503,
        'longitude' => 120.7503,
        'address' => null,
    ]);

    $this->artisan('reports:resolve-addresses')->assertSuccessful();

    expect($report->fresh()->address)->toBeNull();
});

it('honors the limit option', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(['display_name' => resolvedAddress()]),
    ]);

    $first = FloodReport::factory()->create([
        'latitude' => 14.9504,
        'longitude' => 120.7504,
        'address' => null,
    ]);
    $second = FloodReport::factory()->create([
        'latitude' => 14.9505,
        'longitude' => 120.7505,
        'address' => null,
    ]);

    $this->artisan('reports:resolve-addresses', ['--limit' => 1])->assertSuccessful();

    expect($first->fresh()->address)->toBe(resolvedAddress())
        ->and($second->fresh()->address)->toBeNull();
});
