<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\ThreatDetection\ThreatCategory;

/**
 * An incident response playbook binding a threat category to a chain of
 * response steps executed sequentially when that threat is detected.
 */
#[Api(since: '1.0.0')]
final readonly class Playbook
{
    /**
     * @param list<PlaybookStepInterface> $steps
     */
    public function __construct(
        public ThreatCategory $trigger,
        public array $steps,
        public string $name = '',
    ) {}

    #[NoDiscard]
    public function effectiveName(): string
    {
        return $this->name !== '' ? $this->name : 'playbook:' . $this->trigger->value;
    }
}
