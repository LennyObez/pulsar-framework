<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Contracts;

use Pulsar\Api\Api;

/**
 * Value object describing a single Studio navigation entry.
 *
 * Modules return a list of these from {@see StudioModuleInterface::navEntries()}
 * to populate the Studio sidebar navigation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StudioNavEntry
{
    public function __construct(
        public string $label,
        public string $href,
        public string $icon,
        public int $order,
        public ?string $badge = null,
    ) {}
}
