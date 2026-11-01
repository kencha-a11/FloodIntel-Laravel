<?php

it('seeds the authoritative barangays after migrating under the owner credential', function () {
    $script = (string) file_get_contents(base_path('docker/entrypoint.sh'));
    $migrateAt = null;
    $seedAt = null;

    foreach (preg_split('/\R/', $script) as $index => $line) {
        $command = trim($line);

        if (str_contains($command, 'php artisan migrate --force --database=pgsql_owner')) {
            $migrateAt = $index;
        }

        if (str_contains($command, 'php artisan db:seed --class=BarangaySeeder --force --database=pgsql_owner')) {
            $seedAt = $index;
        }
    }

    expect($migrateAt)->not->toBeNull()
        ->and($seedAt)->not->toBeNull()
        ->and($seedAt)->toBeGreaterThan($migrateAt);
});
