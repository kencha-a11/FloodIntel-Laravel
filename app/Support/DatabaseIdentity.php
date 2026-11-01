<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The per-request database identity (FR-65).
 *
 * The runtime connection sets app.user_id with SET LOCAL inside the request's
 * transaction, so a pooled connection cannot carry one user's identity into
 * another request. Polices read it through the SECURITY DEFINER helpers.
 */
final class DatabaseIdentity
{
    /**
     * Run a callback with the given user as the policy identity.
     */
    public static function runAs(int $userId, Closure $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return $callback();
        }

        $previous = (string) $connection->scalar(
            "select coalesce(current_setting('app.user_id', true), '')"
        );

        $connection->beginTransaction();

        try {
            $connection->select(
                "select set_config('app.user_id', ?, true)",
                [(string) $userId],
            );

            $result = $callback();

            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $exception;
        } finally {
            // SET LOCAL reverts only when the outermost transaction ends, so a
            // savepoint (a test, or a caller that opened a transaction) needs
            // an explicit restore.
            if ($connection->transactionLevel() > 0) {
                $connection->select(
                    "select set_config('app.user_id', ?, true)",
                    [$previous],
                );
            }
        }
    }
}
