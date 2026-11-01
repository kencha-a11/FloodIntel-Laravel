<?php

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Locates the PostGIS extension so spatial DDL can name its schema explicitly.
 *
 * Supabase installs extensions into a schema of its own while a stock
 * PostgreSQL installs them into `public`, so a generated column or query that
 * assumes one of them breaks on the other (FR-72).
 */
final class Postgis
{
    /**
     * Return the schema PostGIS lives in, creating the extension when a server
     * has it available but not yet installed.
     */
    public static function ensure(Connection $connection): string
    {
        $schema = self::schema($connection);

        if ($schema !== null) {
            return $schema;
        }

        try {
            $connection->statement('CREATE EXTENSION IF NOT EXISTS postgis');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'PostGIS is required for the spatial migration and could not be created: '.$exception->getMessage()
                .' Enable the postgis extension on the database (on Supabase: Database -> Extensions) and re-run the migration.',
                previous: $exception,
            );
        }

        return self::schema($connection)
            ?? throw new RuntimeException('CREATE EXTENSION reported success but PostGIS is not installed.');
    }

    /**
     * The schema the extension is installed in, or null when it is absent.
     */
    public static function schema(Connection $connection): ?string
    {
        $row = $connection->selectOne(<<<'SQL'
            SELECT n.nspname AS schema_name
            FROM pg_extension e
            JOIN pg_namespace n ON n.oid = e.extnamespace
            WHERE e.extname = 'postgis'
        SQL);

        return $row === null ? null : (string) $row->schema_name;
    }

    /**
     * A schema-qualified call, e.g. `public.ST_MakePoint`.
     */
    public static function call(string $schema, string $function): string
    {
        return $schema.'.'.$function;
    }
}
