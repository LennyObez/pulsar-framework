<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use Psr\Log\LoggerInterface;
use Stringable;

use function is_scalar;
use function is_string;

/**
 * Recording PSR-3 logger shared by the exception-handling tests.
 *
 * Deliberately in its own PSR-4 file rather than beside one of its consumers: a
 * helper declared inside another test file is only reachable once that file
 * happens to have been loaded, which holds under sequential execution and breaks
 * as soon as tests run in separate parallel workers.
 */
final class TestLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $logs = [];

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        /** @var array<string, mixed> $context */
        $this->logs[] = [
            'level' => is_string($level) ? $level : (is_scalar($level) ? (string) $level : 'unknown'),
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
