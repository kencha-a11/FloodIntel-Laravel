<?php

namespace App\Support;

use Throwable;

/**
 * Fallback row 17: transactional mail is fail-closed.
 *
 * A send that cannot reach a transport must not be reported as delivered, and
 * a non-delivering mailer in production must not answer a reset request as if
 * it had (fallback rules 3 and 9).
 */
final class MailAvailability
{
    /**
     * Whether the configured mailer can actually deliver in production.
     */
    public static function isDeliverable(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * Run a send, reporting failure instead of letting it look like success.
     */
    public static function send(callable $send): bool
    {
        if (! self::isDeliverable()) {
            return false;
        }

        try {
            $send();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
