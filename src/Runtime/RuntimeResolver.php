<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Closure;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Environment;

use function extension_loaded;
use function function_exists;

/**
 * Resolves the active runtime type by explicit configuration or auto-detection.
 *
 * Detection priority: FrankenPHP > RoadRunner > Persistent > FPM (fallback).
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class RuntimeResolver
{
    /** @var Closure(): bool */
    private Closure $frankenPhpDetector;

    /** @var Closure(): bool */
    private Closure $roadRunnerDetector;

    /** @var Closure(): bool */
    private Closure $socketsDetector;

    /**
     * @param Closure(): bool|null $frankenPhpDetector Custom detector for FrankenPHP availability
     * @param Closure(): bool|null $roadRunnerDetector Custom detector for RoadRunner availability
     * @param Closure(): bool|null $socketsDetector    Custom detector for sockets extension availability
     */
    public function __construct(
        Environment $environment,
        ?Closure $frankenPhpDetector = null,
        ?Closure $roadRunnerDetector = null,
        ?Closure $socketsDetector = null,
    ) {
        $this->frankenPhpDetector = $frankenPhpDetector
            ?? static fn(): bool => function_exists('frankenphp_handle_request');

        $this->roadRunnerDetector = $roadRunnerDetector
            ?? static fn(): bool => $environment->has('RR_MODE');

        $this->socketsDetector = $socketsDetector
            ?? static fn(): bool => extension_loaded('sockets');
    }

    /**
     * Resolve the runtime type to use.
     *
     * When a configured type is provided, it is returned directly.
     * Otherwise, auto-detection probes the environment in priority order.
     */
    #[NoDiscard]
    public function resolve(?RuntimeType $configured = null): RuntimeType
    {
        if ($configured !== null) {
            return $configured;
        }

        return $this->autoDetect();
    }

    /**
     * List all runtime types available in the current environment.
     *
     * @return list<RuntimeType>
     */
    #[NoDiscard]
    public function available(): array
    {
        $available = [];

        foreach (RuntimeType::cases() as $type) {
            if ($this->isAvailable($type)) {
                $available[] = $type;
            }
        }

        return $available;
    }

    /**
     * Check whether a specific runtime type is available.
     */
    #[NoDiscard]
    public function isAvailable(RuntimeType $type): bool
    {
        return match ($type) {
            RuntimeType::FrankenPhp => ($this->frankenPhpDetector)(),
            RuntimeType::RoadRunner => ($this->roadRunnerDetector)(),
            RuntimeType::Persistent => ($this->socketsDetector)(),
            RuntimeType::Fpm => true,
        };
    }

    private function autoDetect(): RuntimeType
    {
        if (($this->frankenPhpDetector)()) {
            return RuntimeType::FrankenPhp;
        }

        if (($this->roadRunnerDetector)()) {
            return RuntimeType::RoadRunner;
        }

        if (($this->socketsDetector)()) {
            return RuntimeType::Persistent;
        }

        return RuntimeType::Fpm;
    }
}
