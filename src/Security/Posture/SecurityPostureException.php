<?php

declare(strict_types=1);

namespace Pulsar\Security\Posture;

use Pulsar\Api\Api;
use RuntimeException;

use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * Thrown to abort boot when production security-posture enforcement is enabled
 * and the preflight reports blocking items — failing loud instead of letting a
 * security control run silently inert.
 * @api
 */
#[Api(since: '1.0.0')]
final class SecurityPostureException extends RuntimeException
{
    /**
     * @param list<SecurityPostureItem> $blocking
     */
    public static function blocked(array $blocking): self
    {
        $lines = array_map(
            static fn(SecurityPostureItem $i): string => sprintf(
                '  [%s] %s: %s — fix: %s',
                $i->status->value,
                $i->name,
                $i->reason,
                $i->fix,
            ),
            $blocking,
        );

        return new self(sprintf(
            "Security posture preflight failed with %d blocking item(s):\n%s",
            count($blocking),
            implode("\n", $lines),
        ));
    }
}
