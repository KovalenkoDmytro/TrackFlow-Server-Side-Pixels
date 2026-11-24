<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\SecretRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Runs SecretRedactor over each record's message, context and extra.
 *
 * An exception in the context is kept as is unless its text contains a secret; then it is
 * replaced by its redacted string form (class, message and stack trace).
 */
final class SecretProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SecretRedactor::redact($record->message),
            context: $this->context($record->context),
            extra: SecretRedactor::redactArray($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    private function context(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $text = (string) $value;
                $redacted = SecretRedactor::redact($text);
                if ($redacted !== $text) {
                    $context[$key] = $redacted;
                }
            }
        }

        return SecretRedactor::redactArray($context);
    }
}
