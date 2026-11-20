<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * The SITREP privacy boundary (D-45 amendment 2026-11-18, D-147).
 *
 * Supplement payloads carry barangay-level aggregates only. Disallowed
 * structured fields are rejected at the input boundary, not hidden at
 * render time: no individual names, demographics, addresses, coordinates,
 * or photographs anywhere in SITREP inputs, snapshots, or exports.
 * Free-text fields are escaped at render; their content remains a review
 * responsibility of the command center.
 */
final class SitrepPayloadGuard
{
    /**
     * Key names that may never appear at any depth of a payload. Matching
     * normalizes case and strips separators, so `fullName`, `full_name`,
     * and `FULL-NAME` are the same violation.
     *
     * @var list<string>
     */
    private const DISALLOWED = [
        'name', 'names', 'fullname', 'firstname', 'lastname', 'middlename',
        'gender', 'sex', 'age', 'ages', 'birthdate', 'birthday',
        'address', 'addresses', 'street', 'purok', 'sitio', 'householdid',
        'latitude', 'longitude', 'lat', 'lng', 'lon', 'coordinates', 'coordinate',
        'photo', 'photos', 'picture', 'pictures', 'image', 'images',
        'victim', 'victims', 'resident', 'residents', 'casualtyname',
        'causeofdeath', 'causeofinjury',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws ValidationException when a disallowed field is present
     */
    public static function assertClean(array $payload, string $field = 'payload'): void
    {
        $violations = [];
        self::walk($payload, $field, $violations);

        if ($violations !== []) {
            throw ValidationException::withMessages([
                $field => 'SITREP payloads are aggregate-only (D-45); disallowed personal fields: '.implode(', ', $violations).'.',
            ]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $violations
     */
    private static function walk(array $payload, string $path, array &$violations): void
    {
        foreach ($payload as $key => $value) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key) ?? '');
            $childPath = $path.'.'.$key;

            if (in_array($normalized, self::DISALLOWED, true)) {
                $violations[] = $childPath;
            }

            if (is_array($value)) {
                self::walk($value, $childPath, $violations);
            }
        }
    }
}
