<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Wizard;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Contract\FieldInterface;

/**
 * Represents a single step in a multi-step wizard form.
 */
#[Api(since: '1.0.0')]
final readonly class WizardStep
{
    /**
     * @param array<string, FieldInterface> $fields
     */
    public function __construct(
        public int $index,
        public string $label,
        public array $fields,
    ) {}
}
