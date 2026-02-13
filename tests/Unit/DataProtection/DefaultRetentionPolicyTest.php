<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\DefaultRetentionPolicy;
use Pulsar\DataProtection\RetentionPolicyInterface;

#[CoversClass(DefaultRetentionPolicy::class)]
final class DefaultRetentionPolicyTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesFromFullData(): void
    {
        $policy = DefaultRetentionPolicy::fromArray([
            'category' => 'audit_logs',
            'retention_days' => 2555,
            'legal_basis' => 'SOX 7-year requirement',
        ]);

        self::assertSame('audit_logs', $policy->category());
        self::assertSame(2555, $policy->retentionDays());
        self::assertSame('SOX 7-year requirement', $policy->legalBasis());
    }

    #[Test]
    public function fromArrayHandlesEmptyArray(): void
    {
        $policy = DefaultRetentionPolicy::fromArray([]);

        self::assertSame('', $policy->category());
        self::assertSame(0, $policy->retentionDays());
        self::assertSame('', $policy->legalBasis());
    }

    #[Test]
    public function fromArrayClampsNegativeRetentionDaysToZero(): void
    {
        $policy = DefaultRetentionPolicy::fromArray([
            'category' => 'test',
            'retention_days' => -30,
        ]);

        self::assertSame(0, $policy->retentionDays());
    }

    #[Test]
    public function fromArrayHandlesNonStringCategory(): void
    {
        $policy = DefaultRetentionPolicy::fromArray([
            'category' => 42,
            'retention_days' => 10,
        ]);

        self::assertSame('', $policy->category());
    }

    #[Test]
    public function fromArrayHandlesNonIntRetentionDays(): void
    {
        $policy = DefaultRetentionPolicy::fromArray([
            'category' => 'test',
            'retention_days' => '30',
        ]);

        self::assertSame(30, $policy->retentionDays());
    }

    #[Test]
    public function fromArrayHandlesNonNumericRetentionDays(): void
    {
        $policy = DefaultRetentionPolicy::fromArray([
            'category' => 'test',
            'retention_days' => 'not-a-number',
        ]);

        self::assertSame(0, $policy->retentionDays());
    }

    #[Test]
    public function fromArrayHandlesNonStringLegalBasis(): void
    {
        $policy = DefaultRetentionPolicy::fromArray([
            'category' => 'test',
            'retention_days' => 10,
            'legal_basis' => 123,
        ]);

        self::assertSame('', $policy->legalBasis());
    }

    #[Test]
    public function isExpiredReturnsFalseWhenRetentionDaysIsZero(): void
    {
        $policy = new DefaultRetentionPolicy(
            category: 'permanent',
            retentionDays: 0,
        );

        $createdAt = new DateTimeImmutable('2020-01-01');
        $now = new DateTimeImmutable('2030-01-01');

        self::assertFalse($policy->isExpired($createdAt, $now));
    }

    #[Test]
    public function isExpiredReturnsTrueWhenPastRetention(): void
    {
        $policy = new DefaultRetentionPolicy(
            category: 'sessions',
            retentionDays: 30,
        );

        $createdAt = new DateTimeImmutable('2024-01-01');
        $now = new DateTimeImmutable('2024-02-15'); // 45 days later

        self::assertTrue($policy->isExpired($createdAt, $now));
    }

    #[Test]
    public function isExpiredReturnsFalseWhenWithinRetention(): void
    {
        $policy = new DefaultRetentionPolicy(
            category: 'sessions',
            retentionDays: 30,
        );

        $createdAt = new DateTimeImmutable('2024-01-01');
        $now = new DateTimeImmutable('2024-01-20'); // 19 days later

        self::assertFalse($policy->isExpired($createdAt, $now));
    }

    #[Test]
    public function isExpiredReturnsTrueAtExactExpiry(): void
    {
        $policy = new DefaultRetentionPolicy(
            category: 'sessions',
            retentionDays: 30,
        );

        $createdAt = new DateTimeImmutable('2024-01-01T00:00:00+00:00');
        $now = new DateTimeImmutable('2024-01-31T00:00:00+00:00'); // Exactly 30 days

        self::assertTrue($policy->isExpired($createdAt, $now));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('fromArrayEdgeCasesProvider')]
    public function fromArrayHandlesEdgeCases(array $data, string $expectedCategory, int $expectedDays, string $expectedBasis): void
    {
        $policy = DefaultRetentionPolicy::fromArray($data);

        self::assertSame($expectedCategory, $policy->category());
        self::assertSame($expectedDays, $policy->retentionDays());
        self::assertSame($expectedBasis, $policy->legalBasis());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, int, string}>
     */
    public static function fromArrayEdgeCasesProvider(): iterable
    {
        yield 'zero retention' => [
            ['category' => 'permanent', 'retention_days' => 0, 'legal_basis' => 'indefinite'],
            'permanent',
            0,
            'indefinite',
        ];

        yield 'large retention' => [
            ['category' => 'audit', 'retention_days' => 3650, 'legal_basis' => '10 year requirement'],
            'audit',
            3650,
            '10 year requirement',
        ];

        yield 'float retention days' => [
            ['category' => 'test', 'retention_days' => '45.5'],
            'test',
            45,
            '',
        ];
    }

    #[Test]
    public function implementsRetentionPolicyInterface(): void
    {
        $policy = new DefaultRetentionPolicy('test', 30);

        self::assertInstanceOf(RetentionPolicyInterface::class, $policy);
    }
}
