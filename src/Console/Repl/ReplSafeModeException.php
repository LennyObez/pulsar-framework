<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Thrown when a safe-mode restriction blocks an operation in the REPL.
 */
#[Api(since: '1.0.0')]
final class ReplSafeModeException extends RuntimeException
{
    /**
     * A mutating operation was blocked by safe mode.
     */
    #[NoDiscard]
    public static function operationBlocked(string $operation): self
    {
        return new self(sprintf(
            'Operation "%s" is blocked in REPL safe mode. Use --no-safe-mode to allow mutations.',
            $operation,
        ));
    }

    /**
     * A write query was blocked by safe mode.
     */
    #[NoDiscard]
    public static function writeQueryBlocked(string $sql): self
    {
        return new self(sprintf(
            'Write query blocked in REPL safe mode: %s. Only SELECT, EXPLAIN, DESCRIBE, SHOW, PRAGMA, and read-only WITH (CTE) queries are allowed.',
            $sql,
        ));
    }
}
