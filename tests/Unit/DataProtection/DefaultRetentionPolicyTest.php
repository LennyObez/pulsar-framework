<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Exception\ConfigException;
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
    public function fromArrayRefusesAnEntryWithoutACategory(): void
    {
        // Parsing is shared with RetentionPolicy: a policy with no category matches
        // no purger, so the records it should govern would never be purged.
        $this->expectException(ConfigException::class);

        (void) DefaultRetentionPolicy::fromArray([]);
    }

    #[Test]
    public function fromArrayRefusesNegativeRetentionDays(): void
    {
        // Clamping -30 to 0 silently turned a nonsensical value into INDEFINITE
        // retention (0 never expires), which is the opposite of what was written.
        $this->expectException(ConfigException::class);

        (void) DefaultRetentionPolicy::fromArray([
            'category' => 'test',
            'retention_days' => -30,
        ]);
    }

    #[Test]
    public function fromArrayRefusesNonStringCategory(): void
    {
        $this->expectException(ConfigException::class);

        (void) DefaultRetentionPolicy::fromArray([
            'category' => 42,
            'retention_days' => 10,
        ]);
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
    public function fromArrayRefusesNonNumericRetentionDays(): void
    {
        $this->expectException(ConfigException::class);

        (void) DefaultRetentionPolicy::fromArray([
            'category' => 'test',
            'retention_days' => 'not-a-number',
        ]);
    }

    #[Test]
    public function fromArrayAcceptsNumericStringsLikeItsTwin(): void
    {
        // The two classes read the identical config shape and must agree: env()
        // hands over "90" as a string, and it means 90 days in both.
        $policy = DefaultRetentionPolicy::fromArray([
            'category' => 'test',
            'retention_days' => '90',
        ]);

        self::assertSame(90, $policy->retentionDays());
    }

    #[Test]
    public function fromArrayRefusesNonStringLegalBasis(): void
    {
        // The legal basis is what the purge audit trail cites; recording it as an
        // empty string would document no justification at all.
        $this->expectException(ConfigException::class);

        (void) DefaultRetentionPolicy::fromArray([
            'category' => 'test',
            'retention_days' => 10,
            'legal_basis' => 123,
        ]);
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
