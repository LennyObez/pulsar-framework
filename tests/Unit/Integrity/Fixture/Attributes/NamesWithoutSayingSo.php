<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Fixture\Attributes;

/**
 * The defect NoDiscardScanner::withMethodsMissingNoDiscard() exists to find.
 *
 * An immutable modifier that says nothing about its result. `$config->withLabel('x');` on
 * its own line compiles, runs, warns about nothing and does nothing — the caller believes
 * the object was changed and the object is exactly as it was.
 *
 * Kept as a fixture rather than written into a temp file because the rule reads
 * reflection, and reflection needs a class the engine has actually loaded.
 */
final readonly class NamesWithoutSayingSo
{
    public function __construct(public string $label = '') {}

    public function withLabel(string $label): self
    {
        return new self($label);
    }
}
