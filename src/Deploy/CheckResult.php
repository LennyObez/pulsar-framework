<?php

declare(strict_types=1);

namespace Pulsar\Deploy;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable result of a single deploy check.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CheckResult
{
    /**
     * @param list<string> $recommendations Actionable suggestions for fixing the issue
     * @param bool $overridden            F26.4: true when the severity was changed
     *                                    by a SeverityOverrideCheck decorator
     *                                    (config or env). Surfaces the override
     *                                    in reports + audit so an operator
     *                                    cannot silently downgrade a failing
     *                                    check.
     * @param CheckSeverity|null $originalSeverity F26.4: severity reported by
     *                                    the inner check before the override.
     *                                    Null when no override happened.
     */
    public function __construct(
        public string $name,
        public CheckSeverity $severity,
        public string $message,
        public array $recommendations = [],
        public bool $overridden = false,
        public ?CheckSeverity $originalSeverity = null,
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
