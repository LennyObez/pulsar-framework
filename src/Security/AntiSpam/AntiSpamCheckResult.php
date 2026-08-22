<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a single anti-spam check.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AntiSpamCheckResult
{
    /**
     * @param bool $passed Whether the check passed (true = not spam)
     * @param string $checkName Name of the check that produced this result
     * @param int $score Spam score contribution (0 = clean, higher = more suspicious)
     * @param string|null $reason Human-readable reason for failure (null if passed)
     */
    public function __construct(
        public bool $passed,
        public string $checkName,
        public int $score = 0,
        public ?string $reason = null,
    ) {}

    #[NoDiscard]
    public static function pass(string $checkName): self
    {
        return new self(passed: true, checkName: $checkName);
    }

    #[NoDiscard]
    public static function fail(string $checkName, int $score, string $reason): self
    {
        return new self(passed: false, checkName: $checkName, score: $score, reason: $reason);
    }

    #[NoDiscard]
    public static function skip(string $checkName): self
    {
        return new self(passed: true, checkName: $checkName);
    }
}
