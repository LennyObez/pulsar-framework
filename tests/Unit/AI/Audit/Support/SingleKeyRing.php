<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Audit\Support;

use Override;
use Pulsar\Security\Crypto\KeyRingInterface;

/**
 * A key ring holding the one audit key a test wrote its chain with.
 *
 * Enough to drive {@see \Pulsar\Security\Audit\AuditChainVerifier} over entries
 * the inference decorator produced: the question those tests ask is whether the
 * chain still verifies after them, not whether key rotation works.
 */
final readonly class SingleKeyRing implements KeyRingInterface
{
    public function __construct(private string $key) {}

    #[Override]
    public function keyFor(string $kid): string
    {
        return $this->key;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function all(): array
    {
        return ['current' => $this->key];
    }
}
