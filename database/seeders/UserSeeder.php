<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * The password shared by every seeded demo account.
     */
    public const string PASSWORD = 'Password123!';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $barangay = Barangay::query()->first() ?? Barangay::factory()->create();

        $accounts = [
            [
                'role' => UserRole::MdrrmoOfficer,
                'first_name' => 'Mdrrmo',
                'last_name' => 'Officer',
                'email' => 'officer@floodintel.test',
                'contact_number' => '+15550000001',
            ],
            [
                'role' => UserRole::MdrrmoStaff,
                'first_name' => 'Mdrrmo',
                'last_name' => 'Staff',
                'email' => 'staff@floodintel.test',
                'contact_number' => '+15550000002',
            ],
            [
                'role' => UserRole::BarangayCaptain,
                'first_name' => 'Barangay',
                'last_name' => 'Captain',
                'email' => 'captain@floodintel.test',
                'contact_number' => '+15550000003',
            ],
            [
                'role' => UserRole::BarangayTanod,
                'first_name' => 'Barangay',
                'last_name' => 'Tanod',
                'email' => 'tanod@floodintel.test',
                'contact_number' => '+15550000004',
            ],
            [
                'role' => UserRole::RescueLeader,
                'first_name' => 'Rescue',
                'last_name' => 'Leader',
                'email' => 'rescue@floodintel.test',
                'contact_number' => '+15550000005',
            ],
        ];

        foreach ($accounts as $account) {
            $role = $account['role'];

            $user = User::query()->firstOrNew(['email' => $account['email']]);

            $user->first_name = $account['first_name'];
            $user->last_name = $account['last_name'];
            $user->contact_number = $account['contact_number'];
            $user->password = self::PASSWORD;
            $user->role = $role;
            $user->barangay_id = $role->requiresBarangay() ? $barangay->id : null;
            $user->email_verified_at = now();
            $user->save();
        }

        if ($this->command !== null) {
            $this->command->info('Seeded demo accounts (all share the same password):');
            $this->command->table(
                ['Role', 'Email', 'Contact number', 'Password'],
                array_map(fn (array $account): array => [
                    $account['role']->value,
                    $account['email'],
                    $account['contact_number'],
                    self::PASSWORD,
                ], $accounts),
            );
        }
    }
}
