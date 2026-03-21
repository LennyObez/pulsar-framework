<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use Pulsar\Api\Api;

/**
 * Snapshot of the public API surface for the current application.
 *
 * Each class entry includes its API version, exposed methods, and constants.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ApiSnapshotData
{
    /**
     * @param array<string, array{since: string, methods: list<string>, constants: list<string>}> $classes
     */
    public function __construct(
        public array $classes = [],
    ) {}

    /**
     * @return array{classes: array<string, array{since: string, methods: list<string>, constants: list<string>}>}
     */
    public function toArray(): array
    {
        return ['classes' => $this->classes];
    }
}
