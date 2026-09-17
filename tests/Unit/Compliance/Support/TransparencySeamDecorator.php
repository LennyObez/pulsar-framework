<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Support;

use Override;
use Pulsar\Compliance\Evidence\AiTransparencyDrillInterface;

/**
 * A transparency seam that behaves exactly like the real one until a subclass
 * breaks one thing.
 *
 * WHY A DECORATOR AND NOT A DOUBLE. A hand-written fake that fails a check can
 * fail it for a reason the real subsystem could never produce, and a test built
 * on one proves that the observer rejects fakes rather than that it detects
 * deployments. Every defective subsystem in
 * {@see \Pulsar\Tests\Unit\Compliance\Evidence\AiTransparencyObserverTest} is a
 * ONE-METHOD diff from a working one, over the real
 * {@see \Pulsar\Extension\AiGovernance\Internal\Compliance\AiTransparencyDrill}
 * and the real store behind it — a store that forgets a declaration, a marker
 * that will mark anything, a marker that stamps its own clock. Each is something
 * an operator could actually ship, and none of them can pass by getting several
 * things wrong at once.
 */
abstract class TransparencySeamDecorator implements AiTransparencyDrillInterface
{
    public function __construct(
        protected readonly AiTransparencyDrillInterface $inner,
    ) {}

    #[Override]
    public function declareSurface(string $surfaceId, string $notice, string $locale, string $kind): void
    {
        $this->inner->declareSurface($surfaceId, $notice, $locale, $kind);
    }

    /**
     * @return array{
     *     surface_id: string,
     *     owes_disclosure: bool,
     *     owes_marking: bool,
     *     notice: string|null,
     *     locale: string|null
     * }|null
     */
    #[Override]
    public function policyFor(string $surfaceId): ?array
    {
        return $this->inner->policyFor($surfaceId);
    }

    /**
     * @return array{header: string, machine_readable: array<string, scalar>}
     */
    #[Override]
    public function mark(string $surfaceId, string $kind, string $modelId, int $generatedAt): array
    {
        return $this->inner->mark($surfaceId, $kind, $modelId, $generatedAt);
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function declaredSurfaces(): array
    {
        return $this->inner->declaredSurfaces();
    }
}
