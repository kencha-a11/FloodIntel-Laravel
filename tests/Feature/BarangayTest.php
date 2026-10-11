<?php

use App\Models\Barangay;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('returns barangays ordered by name', function () {
    $officer = User::factory()->officer()->create();
    Barangay::factory()->create(['name' => 'Zeta']);
    Barangay::factory()->create(['name' => 'Alpha']);

    $response = $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/barangays');

    $response->assertOk()
        ->assertJsonPath('data.0.name', 'Alpha')
        ->assertJsonStructure(['data' => [['id', 'name', 'code', 'is_active']]]);
});
