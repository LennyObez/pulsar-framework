<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log;

use Pulsar\Api\Api;

/**
 * Log severity levels mapping PSR-3 levels.
 *
 * Lower severity value = more severe (0 = emergency, 7 = debug).
 */
#[Api]
enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert = 'alert';
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
    case Debug = 'debug';

    /**
     * Numeric severity (0 = most severe, 7 = least severe).
     */
    public function severity(): int
    {
        return match ($this) {
            self::Emergency => 0,
            self::Alert => 1,
            self::Critical => 2,
            self::Error => 3,
            self::Warning => 4,
            self::Notice => 5,
            self::Info => 6,
            self::Debug => 7,
        };
    }

    /**
     * Check if this level meets or exceeds the given threshold.
     *
     * A level meets the threshold when its severity value is <= the threshold's value
     * (i.e. it is at least as severe).
     */
    public function meetsThreshold(self $threshold): bool
    {
        return $this->severity() <= $threshold->severity();
    }

    /**
     * Create a LogLevel from a PSR-3 level string or value.
     */
    public static function fromPsrLevel(mixed $level): self
    {
        if ($level instanceof self) {
            return $level;
        }

        $levelString = (string) $level; // @phpstan-ignore cast.string

        return self::tryFrom($levelString) ?? self::Debug;
    }
}
