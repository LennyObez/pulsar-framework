<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Compliance;

use Override;
use Pulsar\Compliance\Control\ControlProbeInterface;
use Pulsar\Compliance\Control\ControlRequirement;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\RequiredFact;

/**
 * A probe over one named fact, used to drive the command's exit semantics from
 * the evidence rather than from a switch in the test.
 *
 * It is written the way a real probe must be: it names the fact it needs and
 * lets {@see \Pulsar\Compliance\Control\ProbeVerdict::reach()} decide. A test
 * double that could return Satisfied unconditionally would be testing a command
 * against a probe the design forbids — and no such double can be written, which
 * is the point of the seal.
 */
final readonly class RecordingProbe implements ControlProbeInterface
{
    /**
     * @param non-empty-string       $id
     * @param non-empty-string       $remediation
     */
    public function __construct(
        private string $id,
        private ObservationId $fact,
        private string $remediation = 'Make the deployment show this control.',
    ) {}

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    #[Override]
    public function describe(): string
    {
        return 'Whether ' . $this->fact->value . ' was observed in this deployment.';
    }

    #[Override]
    public function requirement(): ControlRequirement
    {
        return ControlRequirement::of([
            RequiredFact::contributing($this->fact, [$this->remediation]),
        ]);
    }
}
