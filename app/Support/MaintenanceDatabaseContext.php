<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The narrow context that fills a report's derived address (D-98).
 *
 * The address is written after the response or from a console command, where
 * no user identity exists. Each call opens one transaction as app_maintenance
 * so a provider call never sits inside a database transaction.
 */
final class MaintenanceDatabaseContext
{
    public static function run(Closure $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return $callback();
        }

        $previousRole = (string) $connection->scalar('select current_user');

        $connection->beginTransaction();

        try {
            $connection->statement('set local role app_maintenance');

            $result = $callback();

            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $exception;
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->statement(
                    'set local role '.$connection->getQueryGrammar()->wrap($previousRole)
                );
            }
        }
    }
}
