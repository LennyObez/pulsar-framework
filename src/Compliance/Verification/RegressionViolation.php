<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Describes a configuration regression that violates a compliance constraint.
 */
#[Api(since: '1.0.0')]
final readonly class RegressionViolation
{
    public function __construct(
        public string $constraint,
        public string $expectedDescription,
        public string $actualDescription,
        public string $remediation,
    ) {}

    /**
     * @return array<string, string>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'constraint' => $this->constraint,
            'expected' => $this->expectedDescription,
            'actual' => $this->actualDescription,
            'remediation' => $this->remediation,
        ];
    }
}
