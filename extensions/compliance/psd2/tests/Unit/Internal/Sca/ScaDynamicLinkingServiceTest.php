<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Internal\Sca;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Config\ScaConfig;
use Pulsar\Extension\Psd2\Domain\ScaChallengeType;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;
use Pulsar\Extension\Psd2\Internal\Sca\InMemoryScaChallengeStore;
use Pulsar\Extension\Psd2\Internal\Sca\ScaDynamicLinkingService;

use function strlen;

final class ScaDynamicLinkingServiceTest extends TestCase
{
    private InMemoryScaChallengeStore $store;
    private ScaDynamicLinkingService $service;

    protected function setUp(): void
    {
        $this->store = new InMemoryScaChallengeStore();
        $this->service = new ScaDynamicLinkingService(
            $this->store,
            new ScaConfig(challengeTimeoutSeconds: 300, codeLength: 8),
        );
    }

    #[Test]
    public function createChallengeReturnsPopulatedChallenge(): void
    {
        $challenge = $this->service->createChallenge(
            transactionId: 'tx_001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
            payeeName: 'Acme Corp',
            type: ScaChallengeType::Totp,
        );

        self::assertSame('tx_001', $challenge->transactionId);
        self::assertSame(5000, $challenge->amountMinorUnits);
        self::assertSame('EUR', $challenge->currency);
        self::assertSame('payee_001', $challenge->payeeId);
        self::assertSame('Acme Corp', $challenge->payeeName);
        self::assertSame(ScaChallengeType::Totp, $challenge->challengeType);
        self::assertFalse($challenge->verified);
        self::assertSame(8, strlen($challenge->authenticationCode));
    }

    #[Test]
    public function createChallengeStoresInStore(): void
    {
        $challenge = $this->service->createChallenge(
            transactionId: 'tx_001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
            payeeName: 'Acme Corp',
        );

        $found = $this->store->find($challenge->challengeId);
        self::assertNotNull($found);
        self::assertSame($challenge->challengeId, $found->challengeId);
    }

    #[Test]
    public function verifyChallengeSucceedsWithCorrectCode(): void
    {
        $challenge = $this->service->createChallenge(
            transactionId: 'tx_001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
            payeeName: 'Acme Corp',
        );

        $verified = $this->service->verifyChallenge(
            challengeId: $challenge->challengeId,
            responseCode: $challenge->authenticationCode,
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        self::assertTrue($verified->verified);
        self::assertSame($challenge->transactionId, $verified->transactionId);
    }

    #[Test]
    public function verifyChallengeRemovesFromStoreOnSuccess(): void
    {
        $challenge = $this->service->createChallenge(
            transactionId: 'tx_001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
            payeeName: 'Acme Corp',
        );

        $this->service->verifyChallenge(
            challengeId: $challenge->challengeId,
            responseCode: $challenge->authenticationCode,
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        self::assertNull($this->store->find($challenge->challengeId));
    }

    #[Test]
    public function verifyChallengeThrowsOnNotFound(): void
    {
        $this->expectException(Psd2Exception::class);
        $this->expectExceptionMessage('not found');

        $this->service->verifyChallenge(
            challengeId: 'nonexistent',
            responseCode: 'code',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );
    }

    #[Test]
    public function verifyChallengeThrowsOnExpiredChallenge(): void
    {
        // Create a service with a very short timeout
        $service = new ScaDynamicLinkingService(
            $this->store,
            new ScaConfig(challengeTimeoutSeconds: -1, codeLength: 8),
        );

        $challenge = $service->createChallenge(
            transactionId: 'tx_001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
            payeeName: 'Acme Corp',
        );

        $this->expectException(Psd2Exception::class);
        $this->expectExceptionMessage('expired');

        $service->verifyChallenge(
            challengeId: $challenge->challengeId,
            responseCode: $challenge->authenticationCode,
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );
    }

    #[Test]
    public function verifyChallengeThrowsOnDynamicLinkMismatchAmount(): void
    {
        $challenge = $this->service->createChallenge(
            transactionId: 'tx_001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
            payeeName: 'Acme Corp',
        );

        $this->expectException(Psd2Exception::class);
        $this->expectExceptionMessage('do not match');

        $this->service->verifyChallenge(
            challengeId: $challenge->challengeId,
            responseCode: $challenge->authenticationCode,
            amountMinorUnits: 9999, // modified amount
            currency: 'EUR',
            payeeId: 'payee_001',
        );
    }

    #[Test]
    public function verifyChallengeThrowsOnDynamicLinkMismatchPayee(): void
    {
        $challenge = $this->service->createChallenge(
            transactionId: 'tx_001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
            payeeName: 'Acme Corp',
        );

        $this->expectException(Psd2Exception::class);
        $this->expectExceptionMessage('do not match');

        $this->service->verifyChallenge(
            challengeId: $challenge->challengeId,
            responseCode: $challenge->authenticationCode,
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'attacker_payee', // modified payee
        );
    }

    #[Test]
    public function verifyChallengeThrowsOnInvalidCode(): void
    {
        $challenge = $this->service->createChallenge(
            transactionId: 'tx_001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
            payeeName: 'Acme Corp',
        );

        $this->expectException(Psd2Exception::class);
        $this->expectExceptionMessage('Invalid authentication code');

        $this->service->verifyChallenge(
            challengeId: $challenge->challengeId,
            responseCode: 'wrong_code',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );
    }

    #[Test]
    public function differentTransactionDetailsProduceDifferentCodes(): void
    {
        $c1 = $this->service->createChallenge('tx_001', 5000, 'EUR', 'p1', 'P1');
        $c2 = $this->service->createChallenge('tx_002', 5001, 'EUR', 'p1', 'P1');
        $c3 = $this->service->createChallenge('tx_003', 5000, 'EUR', 'p2', 'P2');

        // Codes should differ because transaction details differ
        self::assertNotSame($c1->authenticationCode, $c2->authenticationCode);
        self::assertNotSame($c1->authenticationCode, $c3->authenticationCode);
    }
}
