<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Snapshot;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

/**
 * A single field with its data classification level.
 *
 * Supports controls for SOX audit trail requirements by associating
 * sensitivity metadata with individual data fields.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ClassifiedField
{
    public function __construct(
        public string $name,
        public mixed $value,
        public DataClassification $classification,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'classification' => $this->classification->value,
        ];
    }
}
