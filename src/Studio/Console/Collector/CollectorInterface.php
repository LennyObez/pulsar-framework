<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Collector;

use Pulsar\Api\Internal;

/**
 * Contract for Studio event collectors.
 *
 * Each collector is responsible for capturing domain-specific events
 * and forwarding them with their correlation context.
 */
#[Internal]
interface CollectorInterface
{
    /**
     * Whether this collector is currently enabled.
     */
    public function isEnabled(): bool;

    /**
     * Enable or disable this collector.
     */
    public function setEnabled(bool $enabled): void;
}
