<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Security;

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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function allowRawPayload(): bool
    {
        return $this->mode === EnvironmentMode::Local;
    }

    public function allowApi(): bool
    {
        return $this->mode !== EnvironmentMode::Production;
    }

    public function allowMutableApi(): bool
    {
        return $this->mode === EnvironmentMode::Local;
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function mode(): EnvironmentMode
    {
        return $this->mode;
    }
}
