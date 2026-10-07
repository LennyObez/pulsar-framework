<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Compliance;

use Override;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Evidence\EvidenceSourceInterface;
use RuntimeException;

/**
 * A collaborator that cannot observe the deployment.
 *
 * Gathering touches a database and executes health checks, so it can genuinely
 * fail. What must not happen is that such a failure prints as a compliance
 * finding: "the deployment does not show this" and "we could not look" are
 * different claims, and only the first belongs in an assessor's document.
 */
final readonly class ThrowingEvidenceSource implements EvidenceSourceInterface
{
    #[Override]
    public function gather(): ControlEvidence
    {
        throw new RuntimeException('the database refused the connection');
    }
}
