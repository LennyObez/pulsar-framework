<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Domain\ScaChallenge;
use Pulsar\Extension\Psd2\Domain\ScaChallengeType;

final class ScaChallengeTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $expires = new DateTimeImmutable('2026-01-15T10:05:00+00:00');

        $challenge = new ScaChallenge(
            challengeId: 'ch_001',
            transactionId: 'tx_001',
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
            payeeName: 'Acme Corp',
            authenticationCode: 'abc12345',
            challengeType: ScaChallengeType::Totp,
            createdAt: $now,
            expiresAt: $expires,
        );

        self::assertSame('ch_001', $challenge->challengeId);
        self::assertSame('tx_001', $challenge->transactionId);
        self::assertSame(5000, $challenge->amountMinorUnits);
        self::assertSame('EUR', $challenge->currency);
        self::assertSame('payee_001', $challenge->payeeId);
        self::assertSame('Acme Corp', $challenge->payeeName);
        self::assertSame('abc12345', $challenge->authenticationCode);
        self::assertSame(ScaChallengeType::Totp, $challenge->challengeType);
        self::assertFalse($challenge->verified);
    }

    #[Test]
    public function isExpiredReturnsTrueWhenPastExpiresAt(): void
    {
        $challenge = $this->createChallenge(
            expiresAt: new DateTimeImmutable('2026-01-15T10:05:00+00:00'),
        );

        $afterExpiry = new DateTimeImmutable('2026-01-15T10:06:00+00:00');

        self::assertTrue($challenge->isExpired($afterExpiry));
    }

    #[Test]
    public function isExpiredReturnsFalseBeforeExpiresAt(): void
    {
        $challenge = $this->createChallenge(
            expiresAt: new DateTimeImmutable('2026-01-15T10:05:00+00:00'),
        );

        $beforeExpiry = new DateTimeImmutable('2026-01-15T10:04:00+00:00');

        self::assertFalse($challenge->isExpired($beforeExpiry));
    }

    #[Test]
    public function markVerifiedReturnsNewInstanceWithVerifiedTrue(): void
    {
        $challenge = $this->createChallenge();

        self::assertFalse($challenge->verified);

        $verified = $challenge->markVerified();

        self::assertTrue($verified->verified);
        self::assertFalse($challenge->verified); // original unchanged
        self::assertSame($challenge->challengeId, $verified->challengeId);
    }

    #[Test]
    public function matchesTransactionReturnsTrueForExactMatch(): void
    {
        $challenge = $this->createChallenge(
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        self::assertTrue($challenge->matchesTransaction(5000, 'EUR', 'payee_001'));
    }

    #[Test]
    #[DataProvider('mismatchedTransactionProvider')]
    public function matchesTransactionReturnsFalseOnMismatch(int $amount, string $currency, string $payeeId): void
    {
        $challenge = $this->createChallenge(
            amountMinorUnits: 5000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        self::assertFalse($challenge->matchesTransaction($amount, $currency, $payeeId));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function mismatchedTransactionProvider(): iterable
    {
        yield 'wrong amount' => [9999, 'EUR', 'payee_001'];
        yield 'wrong currency' => [5000, 'USD', 'payee_001'];
        yield 'wrong payee' => [5000, 'EUR', 'payee_999'];
        yield 'all wrong' => [1, 'GBP', 'other'];
    }

    #[Test]
    public function toArrayContainsAllFields(): void
    {
        $challenge = $this->createChallenge();
        $array = $challenge->toArray();

        self::assertArrayHasKey('challenge_id', $array);
        self::assertArrayHasKey('transaction_id', $array);
        self::assertArrayHasKey('amount_minor_units', $array);
        self::assertArrayHasKey('currency', $array);
        self::assertArrayHasKey('payee_id', $array);
        self::assertArrayHasKey('payee_name', $array);
        self::assertArrayHasKey('authentication_code', $array);
        self::assertArrayHasKey('challenge_type', $array);
        self::assertArrayHasKey('created_at', $array);
        self::assertArrayHasKey('expires_at', $array);
        self::assertArrayHasKey('verified', $array);
        self::assertSame('totp', $array['challenge_type']);
    }

    private function createChallenge(
        int $amountMinorUnits = 5000,
        string $currency = 'EUR',
        string $payeeId = 'payee_001',
        ?DateTimeImmutable $expiresAt = null,
    ): ScaChallenge {
        return new ScaChallenge(
            challengeId: 'ch_001',
            transactionId: 'tx_001',
            amountMinorUnits: $amountMinorUnits,
            currency: $currency,
            payeeId: $payeeId,
            payeeName: 'Acme Corp',
            authenticationCode: 'abc12345',
            challengeType: ScaChallengeType::Totp,
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            expiresAt: $expiresAt ?? new DateTimeImmutable('2026-01-15T10:05:00+00:00'),
        );
    }
}
