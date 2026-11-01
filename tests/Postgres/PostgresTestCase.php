<?php

namespace Tests\Postgres;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class PostgresTestCase extends TestCase
{
    protected function setUp(): void
    {
        $url = getenv('DB_POSTGRES_TEST_URL') ?: '';

        if ($url === '') {
            $this->markTestSkipped(
                'Postgres suite not configured: set DB_POSTGRES_TEST_URL to a disposable database and run with -c phpunit.postgres.xml.'
            );
        }

        $database = PostgresTestDatabase::name($url);
        $allowNonTestDatabase = getenv('POSTGRES_TEST_ALLOW_NON_TEST_DB') === '1';

        if (! PostgresTestDatabase::isDisposable($database, $allowNonTestDatabase)) {
            $this->markTestSkipped(sprintf(
                'Refusing to run destructive Postgres tests against "%s": the database name must contain "test" (set POSTGRES_TEST_ALLOW_NON_TEST_DB=1 to override).',
                $database ?? 'unknown'
            ));
        }

        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Postgres suite requires phpunit.postgres.xml; the active connection is not pgsql.'
            );
        }
    }
}
