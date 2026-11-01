<?php

namespace App\Support;

use App\Enums\AlertLevel;
use App\Enums\FloodImpactStatus;
use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Enums\FloodType;
use BackedEnum;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * The database-level integrity constraints (FR-71).
 *
 * The value lists are generated from the PHP enums, so a new case reaches the
 * migration and the drift test rather than living in two places.
 */
final class DatabaseIntegrity
{
    /**
     * Enum-backed string columns and the enum that owns their values.
     *
     * @var array<string, array<string, class-string<BackedEnum>>>
     */
    public const ENUM_COLUMNS = [
        'flood_reports' => [
            'flood_level' => FloodLevel::class,
            'alert_level' => AlertLevel::class,
            'flood_type' => FloodType::class,
            'status' => FloodReportStatus::class,
        ],
        'flood_impact_reports' => [
            'status' => FloodImpactStatus::class,
        ],
    ];

    /**
     * Coordinate columns and their inclusive bounds, mirroring
     * StoreFloodReportRequest.
     *
     * @var list<array{table: string, column: string, min: int, max: int}>
     */
    public const RANGES = [
        ['table' => 'flood_reports', 'column' => 'latitude', 'min' => -90, 'max' => 90],
        ['table' => 'flood_reports', 'column' => 'longitude', 'min' => -180, 'max' => 180],
    ];

    /**
     * The values an enum column may hold.
     *
     * @return list<string>
     */
    public static function values(string $enum): array
    {
        return array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
    }

    public static function constraintName(string $table, string $column): string
    {
        return $table.'_'.$column.'_allowed_values';
    }

    public static function rangeConstraintName(string $table, string $column): string
    {
        return $table.'_'.$column.'_range';
    }

    /**
     * The CHECK body for an enum column: nullable, but otherwise one of the
     * enum's cases.
     */
    public static function checkClause(string $column, string $enum): string
    {
        $values = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            self::values($enum),
        ));

        return $column.' IS NULL OR '.$column.' IN ('.$values.')';
    }

    /**
     * Refuse to add the constraints while a stored row would violate them.
     */
    public static function assertExistingRowsAreValid(Connection $connection): void
    {
        $failures = [];

        foreach (self::ENUM_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $enum) {
                $values = self::values($enum);
                $placeholders = implode(', ', array_fill(0, count($values), '?'));

                $count = (int) $connection->scalar(
                    "SELECT COUNT(*) FROM {$table} WHERE {$column} IS NOT NULL AND {$column} NOT IN ({$placeholders})",
                    $values,
                );

                if ($count > 0) {
                    $failures[] = "{$table}.{$column}: {$count} row(s) outside the {$enum} cases";
                }
            }
        }

        foreach (self::RANGES as ['table' => $table, 'column' => $column, 'min' => $min, 'max' => $max]) {
            $count = (int) $connection->scalar(
                "SELECT COUNT(*) FROM {$table} WHERE {$column} < ? OR {$column} > ?",
                [$min, $max],
            );

            if ($count > 0) {
                $failures[] = "{$table}.{$column}: {$count} row(s) outside {$min}..{$max}";
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(
                'Refusing to add database constraints: existing rows violate them — '.implode('; ', $failures)
                .'. Fix or remove those rows, or add the missing enum case, before migrating.'
            );
        }
    }
}
