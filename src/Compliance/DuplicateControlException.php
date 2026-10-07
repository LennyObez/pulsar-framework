<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use LogicException;
use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when two mappings declare the same control of the same framework.
 *
 * A LogicException: it is a defect in the mapping files, discovered at boot,
 * never a fact about the deployment.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class DuplicateControlException extends LogicException
{
    #[NoDiscard]
    public static function forControl(ComplianceFramework $framework, string $id): self
    {
        return new self(sprintf(
            'Control "%s" of framework "%s" is declared twice. Two declarations of one control '
                . 'can disagree, and under silent replacement the only symptom is that whichever '
                . 'mapping ran last decides what the report says.',
            $id,
            $framework->value,
        ));
    }
}
