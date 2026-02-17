<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Wizard;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Contract\FieldInterface;

/**
 * Represents a single step in a multi-step wizard form.
 */
#[Api(since: '1.0.0')]
final class WizardStep
{
    /**
     * @param array<string, FieldInterface> $fields
     */
    public function __construct(
        public readonly int $index,
        public readonly string $label,
        public readonly array $fields,
    ) {}
}
