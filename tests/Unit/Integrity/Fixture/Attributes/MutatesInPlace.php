<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Fixture\Attributes;

/**
 * A `with*` method on a class that is not readonly, which the rule deliberately leaves
 * alone: a mutable builder returning $this loses nothing when its result is dropped.
 */
final class MutatesInPlace
{
    public string $label = '';

    public function withLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }
}
