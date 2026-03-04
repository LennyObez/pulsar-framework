<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Psd2\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Domain\ScaChallenge;
use Pulsar\Extension\Psd2\Domain\ScaChallengeType;

#[CoversClass(ScaChallenge::class)]
final class ScaChallengeTest extends TestCase
{
    #[Test]
    public function isExpiredReturnsTrueWhenPastExpiryTime(): void
    {
        $challenge = $this->makeChallenge(
            expiresAt: new DateTimeImmutable('2025-01-01 12:00:00'),
        );

        self::assertTrue($challenge->isExpired(new DateTimeImmutable('2025-01-01 12:00:01')));
    }

    #[Test]
    public function isExpiredReturnsFalseBeforeExpiryTime(): void
    {
        $challenge = $this->makeChallenge(
            expiresAt: new DateTimeImmutable('2025-01-01 12:00:00'),
        );

        self::assertFalse($challenge->isExpired(new DateTimeImmutable('2025-01-01 11:59:59')));
    }

    #[Test]
    public function markVerifiedReturnsNewInstanceWithVerifiedTrue(): void
    {
        $challenge = $this->makeChallenge();
        self::assertFalse($challenge->verified);

        $verified = $challenge->markVerified();

        self::assertTrue($verified->verified);
        self::assertFalse($challenge->verified);
    }

    #[Test]
    public function matchesTransactionReturnsTrueForExactMatch(): void
    {
        $challenge = $this->makeChallenge(
            amountMinorUnits: 10000,
            currency: 'EUR',
            payeeId: 'merchant-42',
        );

        self::assertTrue($challenge->matchesTransaction(10000, 'EUR', 'merchant-42'));
    }

    #[Test]
    public function matchesTransactionReturnsFalseForAmountMismatch(): void
    {
        $challenge = $this->makeChallenge(amountMinorUnits: 10000);

        self::assertFalse($challenge->matchesTransaction(10001, 'EUR', 'merchant-42'));
    }

    #[Test]
    public function matchesTransactionReturnsFalseForCurrencyMismatch(): void
    {
        $challenge = $this->makeChallenge(currency: 'EUR');

        self::assertFalse($challenge->matchesTransaction(10000, 'USD', 'merchant-42'));
    }

    #[Test]
    public function matchesTransactionReturnsFalseForPayeeMismatch(): void
    {
        $challenge = $this->makeChallenge(payeeId: 'merchant-42');

        self::assertFalse($challenge->matchesTransaction(10000, 'EUR', 'merchant-99'));
    }

    #[Test]
    public function toArrayContainsAllFields(): void
    {
        $created = new DateTimeImmutable('2025-03-15 10:00:00');
        $expires = new DateTimeImmutable('2025-03-15 10:05:00');

        $challenge = new ScaChallenge(
            challengeId: 'ch-001',
            transactionId: 'tx-001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee-1',
            payeeName: 'Acme Corp',
            authenticationCode: 'auth-code-123',
            challengeType: ScaChallengeType::Totp,
            createdAt: $created,
            expiresAt: $expires,
            verified: true,
        );

        $array = $challenge->toArray();

        self::assertSame('ch-001', $array['challenge_id']);
        self::assertSame('tx-001', $array['transaction_id']);
        self::assertSame(5000, $array['amount_minor_units']);
        self::assertSame('EUR', $array['currency']);
        self::assertSame('payee-1', $array['payee_id']);
        self::assertSame('totp', $array['challenge_type']);
        self::assertTrue($array['verified']);
    }

    private function makeChallenge(
        int $amountMinorUnits = 10000,
        string $currency = 'EUR',
        string $payeeId = 'merchant-42',
        ?DateTimeImmutable $expiresAt = null,
    ): ScaChallenge {
        return new ScaChallenge(
            challengeId: 'ch-test',
            transactionId: 'tx-test',
            amountMinorUnits: $amountMinorUnits,
            currency: $currency,
            payeeId: $payeeId,
            payeeName: 'Test Payee',
            authenticationCode: 'code-123',
            challengeType: ScaChallengeType::Totp,
            createdAt: new DateTimeImmutable('2025-01-01 11:55:00'),
            expiresAt: $expiresAt ?? new DateTimeImmutable('2025-01-01 12:00:00'),
        );
    }
}
