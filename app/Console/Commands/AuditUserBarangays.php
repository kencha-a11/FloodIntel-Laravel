<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

class AuditUserBarangays extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:audit-barangay {--json : Print the result as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Report accounts whose role requires a barangay but whose barangay is unset';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $roles = array_values(array_filter(
            UserRole::cases(),
            fn (UserRole $role): bool => $role->requiresBarangay(),
        ));

        $values = array_map(fn (UserRole $role): string => $role->value, $roles);

        $accounts = User::query()
            ->whereIn('role', $values)
            ->get(['id', 'email', 'role', 'barangay_id']);

        $violations = $accounts
            ->filter(fn (User $user): bool => $user->barangay_id === null)
            ->values();

        $summary = [];

        foreach ($roles as $role) {
            $inRole = $accounts->filter(fn (User $user): bool => $user->role === $role);

            $summary[$role->value] = [
                'label' => $role->label(),
                'accounts' => $inRole->count(),
                'missing_barangay' => $inRole->filter(fn (User $user): bool => $user->barangay_id === null)->count(),
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'checked_at' => now()->toIso8601String(),
                'roles' => $summary,
                'accounts' => $accounts->count(),
                'missing_barangay' => $violations->count(),
                'violations' => $violations->map(fn (User $user): array => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role->value,
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $violations->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Role', 'Accounts', 'Missing barangay'],
            collect($summary)
                ->map(fn (array $row): array => [$row['label'], $row['accounts'], $row['missing_barangay']])
                ->all(),
        );

        if ($violations->isEmpty()) {
            $this->info("No account is missing its barangay ({$accounts->count()} checked).");

            return self::SUCCESS;
        }

        $this->warn("{$violations->count()} account(s) require a barangay but have none:");
        $this->table(
            ['ID', 'Email', 'Role'],
            $violations->map(fn (User $user): array => [$user->id, $user->email, $user->role->value])->all(),
        );

        return self::FAILURE;
    }
}
