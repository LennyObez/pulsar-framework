<?php

declare(strict_types=1);

namespace Pulsar\Studio\Security;

use Pulsar\Api\Internal;
use Pulsar\Config\EnvironmentMode;

/**
 * Controls visibility and access per environment mode.
 *
 * In production: no drill-down, no traces, no SSE.
 * In staging: redacted, no raw payload access.
 * In local: full access.
 */
#[Internal]
final readonly class ProductionSafetyMode
{
    public function __construct(
        private EnvironmentMode $mode,
    ) {}

    public function allowDrillDown(): bool
    {
        return $this->mode !== EnvironmentMode::Production;
    }

    public function allowStackTraces(): bool
    {
        return $this->mode === EnvironmentMode::Local;
    }

    public function allowSse(): bool
    {
        return $this->mode !== EnvironmentMode::Production;
    }

    public function allowRawPayload(): bool
    {
        return $this->mode === EnvironmentMode::Local;
    }

    public function allowApi(): bool
    {
        return $this->mode !== EnvironmentMode::Production;
    }

    public function mode(): EnvironmentMode
    {
        return $this->mode;
    }
}
