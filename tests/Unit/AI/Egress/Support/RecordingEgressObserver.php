<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Egress\Support;

use Override;
use Pulsar\AI\Egress\AiEgressDecision;
use Pulsar\AI\Egress\AiEgressObserverInterface;
use Pulsar\AI\Egress\AiEgressOutcome;

use function array_map;
use function count;

/**
 * Keeps every decision the guard reported.
 *
 * The requirement it exists to check is the one about not silently mangling a
 * payload: a redaction that reaches the wire without a decision landing here is
 * exactly the failure mode, so several tests assert on this recorder rather than
 * only on the bytes.
 */
final class RecordingEgressObserver implements AiEgressObserverInterface
{
    /** @var list<AiEgressDecision> */
    private array $decisions = [];

    #[Override]
    public function decided(AiEgressDecision $decision): void
    {
        $this->decisions[] = $decision;
    }

    /**
     * @return list<AiEgressDecision>
     */
    public function decisions(): array
    {
        return $this->decisions;
    }

    public function sawNothing(): bool
    {
        return $this->decisions === [];
    }

    public function count(): int
    {
        return count($this->decisions);
    }

    public function only(): ?AiEgressDecision
    {
        return count($this->decisions) === 1 ? $this->decisions[0] : null;
    }

    /**
     * @return list<AiEgressOutcome>
     */
    public function outcomes(): array
    {
        return array_map(
            static fn(AiEgressDecision $decision): AiEgressOutcome => $decision->outcome,
            $this->decisions,
        );
    }
}
