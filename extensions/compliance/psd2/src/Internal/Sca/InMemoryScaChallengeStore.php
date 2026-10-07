<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Sca;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Psd2\Contracts\ScaChallengeStoreInterface;
use Pulsar\Extension\Psd2\Domain\ScaChallenge;

/**
 * In-memory SCA challenge store for development and testing.
 */
#[Internal(reason: 'Use ScaChallengeStoreInterface for production implementations')]
final class InMemoryScaChallengeStore implements ScaChallengeStoreInterface
{
    /** @var array<string, ScaChallenge> */
    private array $challenges = [];

    #[Override]
    public function store(ScaChallenge $challenge): void
    {
        $this->challenges[$challenge->challengeId] = $challenge;
    }

    #[Override]
    public function find(string $challengeId): ?ScaChallenge
    {
        return $this->challenges[$challengeId] ?? null;
    }

    #[Override]
    public function remove(string $challengeId): void
    {
        unset($this->challenges[$challengeId]);
    }
}
