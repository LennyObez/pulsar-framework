<?php

declare(strict_types=1);

namespace Pulsar\Container\Internal;

use NoDiscard;
use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\CompositionRoots;
use Pulsar\Container\BindingType;
use Pulsar\Container\ContainerInterface;
use RuntimeException;

use function debug_backtrace;
use function preg_match;
use function str_contains;
use function str_starts_with;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 * Development-only decorator that checks module boundary rules at container resolution time.
 *
 * Wraps ContainerInterface and validates that the resolved service's namespace
 * is accessible from the caller's namespace according to the same boundary
 * rules enforced by Deptrac at build time.
 *
 * Behavior:
 * - In development mode: active, logs or throws on violations
 * - In production: this decorator is NOT applied (zero overhead)
 *
 * @internal Not part of the public API
 */
final readonly class BoundaryGuard implements ContainerInterface
{
    public function __construct(
        private ContainerInterface $inner,
        private LoggerInterface $logger,
        private bool $throwOnViolation = false,
    ) {}

    #[Override]
    public function has(string $id): bool
    {
        return $this->inner->has($id);
    }

    #[Override]
    #[NoDiscard]
    public function get(string $id): mixed
    {
        $this->checkBoundary($id);

        return $this->inner->get($id);
    }

    #[Override]
    public function bind(string $id, callable|string $concrete, BindingType $type = BindingType::Singleton): void
    {
        $this->inner->bind($id, $concrete, $type);
    }

    #[Override]
    public function singleton(string $id, callable|string $concrete): void
    {
        $this->inner->singleton($id, $concrete);
    }

    #[Override]
    public function decorate(string $id, string|callable $decorator, int $priority = 0): void
    {
        $this->inner->decorate($id, $decorator, $priority);
    }

    #[Override]
    public function instance(string $id, object $instance): void
    {
        $this->inner->instance($id, $instance);
    }

    #[Override]
    public function forgetInstance(string $id): void
    {
        $this->inner->forgetInstance($id);
    }

    #[Override]
    public function setResolutionHints(?array $hints): void
    {
        $this->inner->setResolutionHints($hints);
    }

    #[Override]
    #[NoDiscard]
    public function getBindings(): array
    {
        return $this->inner->getBindings();
    }

    #[Override]
    #[NoDiscard]
    public function getInstances(): array
    {
        return $this->inner->getInstances();
    }

    #[Override]
    public function call(callable $callable, array $params = []): mixed
    {
        return $this->inner->call($callable, $params);
    }

    private function checkBoundary(string $serviceId): void
    {
        // Only check Pulsar namespace services
        if (!str_starts_with($serviceId, 'Pulsar\\')) {
            return;
        }

        $callerClass = $this->resolveCallerClass();
        if ($callerClass === null) {
            return;
        }

        // Composition roots are exempt
        if ($this->isCompositionRoot($callerClass)) {
            return;
        }

        $callerModule = $this->extractModule($callerClass);
        $serviceModule = $this->extractModule($serviceId);

        if ($callerModule === null || $serviceModule === null) {
            return;
        }

        // Same module is always allowed
        if ($callerModule === $serviceModule) {
            return;
        }

        // Cross-module access to \Internal\ namespace is a violation
        if (str_contains($serviceId, '\\Internal\\')) {
            $this->reportViolation($callerClass, $serviceId);
        }
    }

    /**
     * Extract the module name from a FQCN.
     *
     * Returns "Extension\\{Name}" for extensions, or the first namespace
     * segment after "Pulsar\\" for core modules.
     */
    private function extractModule(string $fqcn): ?string
    {
        if (preg_match('/^Pulsar\\\\Extension\\\\([^\\\\]+)/', $fqcn, $matches) === 1) {
            return 'Extension\\' . $matches[1];
        }

        if (preg_match('/^Pulsar\\\\([^\\\\]+)/', $fqcn, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function isCompositionRoot(string $fqcn): bool
    {
        // Delegated rather than restated. This guard used to carry its own copy of the
        // list, one of three that had already drifted: the static boundary checker
        // treated Pulsar\Core\Boot\ as a root and this one did not, so a class there
        // passed the gate and would have been refused when it ran. The local copy also
        // prefix-matched its exact class names, quietly exempting anything starting with
        // `Pulsar\Core\Kernel` — KernelHandler included.
        return CompositionRoots::contains($fqcn);
    }

    /**
     * Walk the debug backtrace to find the first Pulsar class outside the Container module.
     */
    private function resolveCallerClass(): ?string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        foreach ($trace as $frame) {
            $class = $frame['class'] ?? null;
            if ($class === null) {
                continue;
            }

            // Skip container internals
            if (str_starts_with($class, 'Pulsar\\Container\\')) {
                continue;
            }

            // Skip this class
            if ($class === self::class) {
                continue;
            }

            // Only care about Pulsar namespace callers
            if (str_starts_with($class, 'Pulsar\\')) {
                return $class;
            }
        }

        return null;
    }

    private function reportViolation(string $caller, string $serviceId): void
    {
        $logMessage = "Boundary violation: Cross-module resolution of \\Internal\\ service: $caller resolved $serviceId";

        $this->logger->warning($logMessage, [
            'caller' => $caller,
            'service' => $serviceId,
        ]);

        if ($this->throwOnViolation) {
            throw new RuntimeException($logMessage);
        }
    }
}
