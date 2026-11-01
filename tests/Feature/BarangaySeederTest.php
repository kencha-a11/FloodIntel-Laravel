<?php

use App\Models\Barangay;
use Database\Seeders\BarangaySeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('seeds the twelve authoritative barangays from the served release', function () {
    $this->seed(BarangaySeeder::class);

    expect(Barangay::query()->orderBy('code')->get(['code', 'name'])->toArray())->toBe([
        ['code' => 'PH0305402001', 'name' => 'Balucuc'],
        ['code' => 'PH0305402003', 'name' => 'Calantipe'],
        ['code' => 'PH0305402004', 'name' => 'Cansinala'],
        ['code' => 'PH0305402005', 'name' => 'Capalangan'],
        ['code' => 'PH0305402007', 'name' => 'Colgante'],
        ['code' => 'PH0305402009', 'name' => 'Paligui'],
        ['code' => 'PH0305402010', 'name' => 'Sampaloc'],
        ['code' => 'PH0305402011', 'name' => 'San Juan (Pob.)'],
        ['code' => 'PH0305402012', 'name' => 'San Vicente'],
        ['code' => 'PH0305402013', 'name' => 'Sucad'],
        ['code' => 'PH0305402014', 'name' => 'Sulipan'],
        ['code' => 'PH0305402015', 'name' => 'Tabuyuc (Santo Rosario)'],
    ])->and(Barangay::query()->where('is_active', true)->count())->toBe(12);
});

it('deactivates a superseded placeholder without deleting it', function () {
    $placeholder = Barangay::factory()->create([
        'code' => 'BRGY-001',
        'name' => 'Barangay Poblacion',
    ]);

    $this->seed(BarangaySeeder::class);

    $this->assertModelExists($placeholder);
    expect($placeholder->fresh()->is_active)->toBeFalse()
        ->and(Barangay::query()->where('is_active', true)->count())->toBe(12);
});

it('changes nothing when it runs a second time', function () {
    $this->seed(BarangaySeeder::class);
    $first = Barangay::query()->orderBy('id')->pluck('code', 'id')->all();

    $this->seed(BarangaySeeder::class);

    expect(Barangay::query()->orderBy('id')->pluck('code', 'id')->all())->toBe($first);
});
