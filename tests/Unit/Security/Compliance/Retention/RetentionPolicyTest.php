<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Retention;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Retention\RetentionPolicy;

#[CoversClass(RetentionPolicy::class)]
final class RetentionPolicyTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $effective = new DateTimeImmutable('2024-01-01');

        $policy = new RetentionPolicy(
            policyId: 'sox-1',
            version: 1,
            regulation: 'SOX',
            retentionPeriodDays: 2555,
            effectiveDate: $effective,
            description: 'SOX 7-year retention',
        );

        self::assertSame('sox-1', $policy->policyId);
        self::assertSame(1, $policy->version);
        self::assertSame('SOX', $policy->regulation);
        self::assertSame(2555, $policy->retentionPeriodDays);
        self::assertSame($effective, $policy->effectiveDate);
        self::assertSame('SOX 7-year retention', $policy->description);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $policy = new RetentionPolicy(
            policyId: 'hipaa-1',
            version: 2,
            regulation: 'HIPAA',
            retentionPeriodDays: 2190,
            effectiveDate: new DateTimeImmutable('2024-06-15'),
            description: 'HIPAA 6-year retention',
        );

        $array = $policy->toArray();

        self::assertSame('hipaa-1', $array['policy_id']);
        self::assertSame(2, $array['version']);
        self::assertSame('HIPAA', $array['regulation']);
        self::assertSame(2190, $array['retention_period_days']);
        self::assertSame('2024-06-15', $array['effective_date']);
        self::assertSame('HIPAA 6-year retention', $array['description']);
    }

    #[Test]
    public function fromArrayCreatesEquivalentPolicy(): void
    {
        $original = new RetentionPolicy(
            policyId: 'pci-1',
            version: 1,
            regulation: 'PCI-DSS',
            retentionPeriodDays: 365,
            effectiveDate: new DateTimeImmutable('2024-01-01'),
            description: 'PCI DSS 1-year retention',
        );

        /** @var array{policy_id: string, version: int|string, regulation: string, retention_period_days: int|string, effective_date: string, description: string} $data */
        $data = $original->toArray();
        $reconstructed = RetentionPolicy::fromArray($data);

        self::assertSame($original->policyId, $reconstructed->policyId);
        self::assertSame($original->version, $reconstructed->version);
        self::assertSame($original->regulation, $reconstructed->regulation);
        self::assertSame($original->retentionPeriodDays, $reconstructed->retentionPeriodDays);
        self::assertSame($original->description, $reconstructed->description);
    }

    #[Test]
    public function fromArrayHandlesStringCasting(): void
    {
        $data = [
            'policy_id' => 'test-1',
            'version' => '3',
            'regulation' => 'GDPR',
            'retention_period_days' => '365',
            'effective_date' => '2024-03-01',
            'description' => 'GDPR minimum retention',
        ];

        $policy = RetentionPolicy::fromArray($data);

        self::assertSame('test-1', $policy->policyId);
        self::assertSame(3, $policy->version);
        self::assertSame(365, $policy->retentionPeriodDays);
    }
}
