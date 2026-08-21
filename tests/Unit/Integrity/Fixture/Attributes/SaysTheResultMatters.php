<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Fixture\Attributes;

use NoDiscard;

/**
 * The same modifier, annotated. The control for the fixture beside it: if the rule
 * reported this one too, "it refused the defect" would only mean "it refuses everything".
 */
final readonly class SaysTheResultMatters
{
    public function __construct(public string $label = '') {}

    #[NoDiscard]
    public function withLabel(string $label): self
    {
        return new self($label);
    }
}
