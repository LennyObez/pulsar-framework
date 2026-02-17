<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;
use RuntimeException;

use function count;
use function implode;
use function sprintf;

/**
 * Thrown when configuration regressions violate the active compliance profile.
 */
#[Api(since: '1.0.0')]
final class ComplianceRegressionException extends RuntimeException
{
    /** @var list<RegressionViolation> */
    private readonly array $violations;

    /**
     * @param list<RegressionViolation> $violations
     */
    private function __construct(string $message, array $violations)
    {
        parent::__construct($message);
        $this->violations = $violations;
    }

    /**
     * @param list<RegressionViolation> $violations
     */
    public static function fromViolations(array $violations): self
    {
        $lines = [];

        foreach ($violations as $violation) {
            $lines[] = sprintf(
                '  - %s: expected %s, got %s',
                $violation->constraint,
                $violation->expectedDescription,
                $violation->actualDescription,
            );
        }

        $message = sprintf(
            "Compliance regression detected (%d violation%s):\n%s",
            count($violations),
            count($violations) !== 1 ? 's' : '',
            implode("\n", $lines),
        );

        return new self($message, $violations);
    }

    /**
     * @return list<RegressionViolation>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
