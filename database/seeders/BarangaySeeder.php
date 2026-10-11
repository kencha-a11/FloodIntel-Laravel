<?php

namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Seeder;

class BarangaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $barangays = [
            ['name' => 'Barangay Poblacion', 'code' => 'BRGY-001'],
            ['name' => 'Barangay San Isidro', 'code' => 'BRGY-002'],
            ['name' => 'Barangay San Roque', 'code' => 'BRGY-003'],
            ['name' => 'Barangay Santo Niño', 'code' => 'BRGY-004'],
            ['name' => 'Barangay Santa Cruz', 'code' => 'BRGY-005'],
            ['name' => 'Barangay San Jose', 'code' => 'BRGY-006'],
        ];

        foreach ($barangays as $barangay) {
            Barangay::query()->firstOrCreate(
                ['code' => $barangay['code']],
                ['name' => $barangay['name'], 'is_active' => true],
            );
        }
    }
}
