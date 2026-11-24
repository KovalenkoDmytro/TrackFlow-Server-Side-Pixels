<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Log channel tap (config/logging.php adds it to every channel): pushes SecretProcessor so
 * tokens never reach log files, syslog or Slack.
 */
final class RedactSecretsInLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof \Monolog\Logger) {
            $monolog->pushProcessor(new SecretProcessor);
        }
    }
}
