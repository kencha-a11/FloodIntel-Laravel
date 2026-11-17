<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;

final class ConfigureDiagnosticLogging
{
    public function __invoke(Logger $logger): void
    {
        $sanitizer = new SanitizeDiagnosticRecord;

        // Sanitize before placeholder interpolation can stringify context objects.
        $logger->pushProcessor($sanitizer);

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                // Re-sanitize context added after stacks flatten channel processors.
                $handler->pushProcessor($sanitizer);
            }

            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter);
            }
        }
    }
}
