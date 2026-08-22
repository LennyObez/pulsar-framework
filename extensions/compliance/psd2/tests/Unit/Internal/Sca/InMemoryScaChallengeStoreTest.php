<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Internal\Sca;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Domain\ScaChallenge;
use Pulsar\Extension\Psd2\Domain\ScaChallengeType;
use Pulsar\Extension\Psd2\Internal\Sca\InMemoryScaChallengeStore;

final class InMemoryScaChallengeStoreTest extends TestCase
{
    #[Test]
    public function storeAndFindReturnsSameChallenge(): void
    {
        $store = new InMemoryScaChallengeStore();
        $challenge = $this->createChallenge('ch_001');

        $store->store($challenge);

        $found = $store->find('ch_001');
        self::assertNotNull($found);
        self::assertSame('ch_001', $found->challengeId);
    }

    #[Test]
    public function findReturnsNullForUnknownId(): void
    {
        $store = new InMemoryScaChallengeStore();

        self::assertNull($store->find('nonexistent'));
    }

    #[Test]
    public function removeDeletesChallenge(): void
    {
        $store = new InMemoryScaChallengeStore();
        $store->store($this->createChallenge('ch_001'));

        $store->remove('ch_001');

        self::assertNull($store->find('ch_001'));
    }

    #[Test]
    public function removeNonExistentIdDoesNotThrow(): void
    {
        $store = new InMemoryScaChallengeStore();

        $store->remove('nonexistent');

        self::assertNull($store->find('nonexistent'));
    }

    #[Test]
    public function storeOverwritesExistingChallenge(): void
    {
        $store = new InMemoryScaChallengeStore();
        $original = $this->createChallenge('ch_001');
        $store->store($original);

        $updated = new ScaChallenge(
            challengeId: 'ch_001',
            transactionId: 'tx_002',
            amountMinorUnits: 9999,
            currency: 'USD',
            payeeId: 'payee_002',
            payeeName: 'Other Corp',
            authenticationCode: 'xyz99999',
            challengeType: ScaChallengeType::Push,
            createdAt: new DateTimeImmutable(),
            expiresAt: new DateTimeImmutable('+5 minutes'),
        );
        $store->store($updated);

        $found = $store->find('ch_001');
        self::assertNotNull($found);
        self::assertSame('tx_002', $found->transactionId);
        self::assertSame(9999, $found->amountMinorUnits);
    }

    private function createChallenge(string $id): ScaChallenge
    {
        return new ScaChallenge(
            challengeId: $id,
            transactionId: 'tx_001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
            payeeName: 'Acme Corp',
            authenticationCode: 'abc12345',
            challengeType: ScaChallengeType::Totp,
            createdAt: new DateTimeImmutable(),
            expiresAt: new DateTimeImmutable('+5 minutes'),
        );
    }
}
