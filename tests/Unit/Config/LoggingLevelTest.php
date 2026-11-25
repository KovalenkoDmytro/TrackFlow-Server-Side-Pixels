<?php

declare(strict_types=1);

/** Evaluates config/logging.php with the given environment variables (unset when null). */
function loggingConfigWith(array $vars): array
{
    $previous = [];
    foreach ($vars as $key => $value) {
        $previous[$key] = getenv($key);
        $value === null ? putenv($key) : putenv("{$key}={$value}");
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    try {
        return require base_path('config/logging.php');
    } finally {
        foreach ($previous as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
            if ($value === false) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
    }
}

it('defaults to warning in production when LOG_LEVEL is unset', function (): void {
    $config = loggingConfigWith(['APP_ENV' => 'production', 'LOG_LEVEL' => null]);

    expect($config['channels']['single']['level'])->toBe('warning')
        ->and($config['channels']['daily']['level'])->toBe('warning');
});

it('defaults to debug outside production', function (): void {
    expect(loggingConfigWith(['APP_ENV' => 'local', 'LOG_LEVEL' => null])['channels']['single']['level'])->toBe('debug');
});

it('honours an explicit LOG_LEVEL', function (): void {
    expect(loggingConfigWith(['APP_ENV' => 'production', 'LOG_LEVEL' => 'info'])['channels']['single']['level'])->toBe('info');
});
