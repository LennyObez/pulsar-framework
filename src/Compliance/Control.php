<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use Pulsar\Api\Api;

/**
 * Immutable DTO representing a single regulatory control.
 *
 * Each control belongs to a compliance framework (e.g., SOC 2, HIPAA) and
 * tracks which framework features provide coverage for its requirements.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Control
{
    /**
     * @param non-empty-string     $id                Unique control identifier (e.g., "CC6.1")
     * @param non-empty-string     $framework         Compliance framework name (e.g., "soc2")
     * @param non-empty-string     $title             Short human-readable title
     * @param non-empty-string     $description       Detailed description of the control requirement
     * @param ControlStatus        $status            Current implementation status
     * @param list<non-empty-string> $frameworkFeatures Features that provide coverage for this control
     */
    public function __construct(
        public string $id,
        public string $framework,
        public string $title,
        public string $description,
        public ControlStatus $status,
        public array $frameworkFeatures = [],
    ) {}
}
