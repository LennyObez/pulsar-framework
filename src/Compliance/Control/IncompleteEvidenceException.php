<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function implode;
use function sprintf;

/**
 * Thrown when the gatherer did not produce an observation for every
 * {@see ObservationId}.
 *
 * Totality is enforced eagerly, in {@see ControlEvidence}'s constructor, rather
 * than lazily at each probe's call site. A gatherer that silently stopped
 * producing a fact must fail the report loudly; the alternative is every probe
 * that reads it quietly concluding something about nothing.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class IncompleteEvidenceException extends RuntimeException
{
    /** @var list<string> */
    private readonly array $missing;

    /**
     * @param list<string> $missing
     */
    private function __construct(string $message, array $missing)
    {
        parent::__construct($message);
        $this->missing = $missing;
    }

    /**
     * @param list<string> $missing Observation ids the gatherer did not produce
     */
    #[NoDiscard]
    public static function notGathered(array $missing): self
    {
        return new self(
            sprintf(
                'Compliance evidence is incomplete: no observation was gathered for %s. '
                    . 'Every ObservationId must be produced on every run; a probe reading an '
                    . 'ungathered fact would conclude from nothing.',
                implode(', ', $missing),
            ),
            $missing,
        );
    }

    /**
     * @return list<string>
     */
    #[NoDiscard]
    public function missing(): array
    {
        return $this->missing;
    }
}
