<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Support;

use function sprintf;

/**
 * One step of one workflow, and whether failing it stops the build.
 *
 * The distinction is the whole reason this object exists. `benchmark-nightly.yml` carries
 * `continue-on-error: true` on both of its substantive steps, so the job named "Tier B:
 * Nightly Performance Benchmarks" cannot fail on a breached budget — it is a report whose
 * name reads like a gate. Counting it as a gate would inflate the enumeration with
 * something no negative test could ever be written for, because there is no refusal to
 * observe; leaving it out silently would hide that a step everyone reads as blocking is
 * not. So it is read, classified, and excluded for a recorded reason.
 */
final readonly class PipelineStep
{
    public function __construct(
        public string $workflow,
        public string $name,
        public int $line,
        public string $run,
        public bool $continueOnError,
    ) {}

    /**
     * Can a failure here stop the build?
     */
    public function blocks(): bool
    {
        return !$this->continueOnError;
    }

    public function describe(): string
    {
        return sprintf('%s:%d "%s"', $this->workflow, $this->line, $this->name);
    }
}
