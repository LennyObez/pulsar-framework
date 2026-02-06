<?php

declare(strict_types=1);

namespace Pulsar\Deploy;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable result of a single deploy check.
 */
#[Api]
readonly class CheckResult
{
    /**
     * @param list<string> $recommendations Actionable suggestions for fixing the issue
     */
    public function __construct(
        public string $name,
        public CheckSeverity $severity,
        public string $message,
        public array $recommendations = [],
    ) {}

    /**
     * Create a passing result.
     *
     * @param list<string> $recommendations
     */
    #[NoDiscard]
    public static function pass(string $name, string $message, array $recommendations = []): self
    {
        return new self(
            name: $name,
            severity: CheckSeverity::Pass,
            message: $message,
            recommendations: $recommendations,
        );
    }

    /**
     * Create a warning result.
     *
     * @param list<string> $recommendations
     */
    #[NoDiscard]
    public static function warning(string $name, string $message, array $recommendations = []): self
    {
        return new self(
            name: $name,
            severity: CheckSeverity::Warning,
            message: $message,
            recommendations: $recommendations,
        );
    }

    /**
     * Create an error result.
     *
     * @param list<string> $recommendations
     */
    #[NoDiscard]
    public static function error(string $name, string $message, array $recommendations = []): self
    {
        return new self(
            name: $name,
            severity: CheckSeverity::Error,
            message: $message,
            recommendations: $recommendations,
        );
    }
}
