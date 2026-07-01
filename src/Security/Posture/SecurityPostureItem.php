<?php

declare(strict_types=1);

namespace Pulsar\Security\Posture;

use Pulsar\Api\Api;

/**
 * A single evaluated security control: what was checked, the outcome, why, and
 * how to fix it when not {@see SecurityPostureStatus::Ok}.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityPostureItem
{
    public function __construct(
        public string $name,
        public SecurityPostureStatus $status,
        public string $reason,
        public string $fix = '',
    ) {}

    public static function ok(string $name, string $reason): self
    {
        return new self($name, SecurityPostureStatus::Ok, $reason);
    }

    public static function degraded(string $name, string $reason, string $fix): self
    {
        return new self($name, SecurityPostureStatus::Degraded, $reason, $fix);
    }

    public static function fail(string $name, string $reason, string $fix): self
    {
        return new self($name, SecurityPostureStatus::Fail, $reason, $fix);
    }
}
