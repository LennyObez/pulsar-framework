<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\RetentionPolicyInterface;

final class RetentionPolicyInterfaceTest extends TestCase
{
    #[Test]
    public function categoryReturnsDataCategory(): void
    {
        $policy = $this->createStub(RetentionPolicyInterface::class);
        $policy->method('category')->willReturn('payment_records');

        self::assertSame('payment_records', $policy->category());
    }

    #[Test]
    public function retentionDaysReturnsNonNegativeInt(): void
    {
        $policy = $this->createStub(RetentionPolicyInterface::class);
        $policy->method('retentionDays')->willReturn(365);

        self::assertSame(365, $policy->retentionDays());
    }

    #[Test]
    public function retentionDaysCanBeZeroForIndefinite(): void
    {
        $policy = $this->createStub(RetentionPolicyInterface::class);
        $policy->method('retentionDays')->willReturn(0);

        self::assertSame(0, $policy->retentionDays());
    }

    #[Test]
    public function legalBasisReturnsJustification(): void
    {
        $policy = $this->createStub(RetentionPolicyInterface::class);
        $policy->method('legalBasis')->willReturn('GDPR Art. 17');

        self::assertSame('GDPR Art. 17', $policy->legalBasis());
    }

    #[Test]
    public function legalBasisReturnsEmptyStringWhenNotConfigured(): void
    {
        $policy = $this->createStub(RetentionPolicyInterface::class);
        $policy->method('legalBasis')->willReturn('');

        self::assertSame('', $policy->legalBasis());
    }

    #[Test]
    public function isExpiredDelegatesToImplementation(): void
    {
        $policy = $this->createStub(RetentionPolicyInterface::class);
        $policy->method('isExpired')->willReturn(true);

        $created = new DateTimeImmutable('2020-01-01');
        $now = new DateTimeImmutable('2025-01-01');

        self::assertTrue($policy->isExpired($created, $now));
    }
}
