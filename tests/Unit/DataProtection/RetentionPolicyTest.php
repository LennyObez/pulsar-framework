<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\RetentionPolicy;

#[CoversClass(RetentionPolicy::class)]
final class RetentionPolicyTest extends TestCase
{
    #[Test]
    public function constructionStoresAllProperties(): void
    {
        $policy = new RetentionPolicy(
            category: 'audit_logs',
            retentionDays: 365,
            legalBasis: 'GDPR Art 5(1)(e)',
        );

        self::assertSame('audit_logs', $policy->category);
        self::assertSame(365, $policy->retentionDays);
        self::assertSame('GDPR Art 5(1)(e)', $policy->legalBasis);
    }

    #[Test]
    public function legalBasisDefaultsToEmptyString(): void
    {
        $policy = new RetentionPolicy(
            category: 'session_data',
            retentionDays: 30,
        );

        self::assertSame('', $policy->legalBasis);
    }

    #[Test]
    public function fromArrayPopulatesAllFields(): void
    {
        $policy = RetentionPolicy::fromArray([
            'category' => 'user_data',
            'retention_days' => 2190,
            'legal_basis' => 'HIPAA 45 CFR 164.530(j)',
        ]);

        self::assertSame('user_data', $policy->category);
        self::assertSame(2190, $policy->retentionDays);
        self::assertSame('HIPAA 45 CFR 164.530(j)', $policy->legalBasis);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $policy = RetentionPolicy::fromArray([]);

        self::assertSame('', $policy->category);
        self::assertSame(0, $policy->retentionDays);
        self::assertSame('', $policy->legalBasis);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $policy = RetentionPolicy::fromArray([
            'category' => 42,
            'retention_days' => 'not_a_number',
            'legal_basis' => false,
        ]);

        self::assertSame('', $policy->category);
        self::assertSame(0, $policy->retentionDays);
        self::assertSame('', $policy->legalBasis);
    }
}
