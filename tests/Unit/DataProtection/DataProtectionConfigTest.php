<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\DataProtection\ConsentConfig;
use Pulsar\DataProtection\DataProtectionConfig;
use Pulsar\DataProtection\PurgeConfig;
use Pulsar\DataProtection\RetentionPolicy;

#[CoversClass(DataProtectionConfig::class)]
#[CoversClass(RetentionPolicy::class)]
#[CoversClass(PurgeConfig::class)]
#[CoversClass(ConsentConfig::class)]
final class DataProtectionConfigTest extends TestCase
{
    #[Test]
    public function fromArrayParsesFullConfig(): void
    {
        $config = DataProtectionConfig::fromArray([
            'retention' => [
                ['category' => 'audit_logs', 'retention_days' => 2555, 'legal_basis' => 'SOX 7-year'],
                ['category' => 'sessions', 'retention_days' => 30],
            ],
            'purge' => [
                'batch_size' => 500,
                'audit_purge_operations' => false,
                'dry_run' => true,
            ],
            'consent' => [
                'require_explicit' => false,
                'purposes' => ['marketing', 'analytics'],
            ],
        ]);

        self::assertCount(2, $config->retention);
        self::assertSame('audit_logs', $config->retention[0]->category);
        self::assertSame(2555, $config->retention[0]->retentionDays);
        self::assertSame('SOX 7-year', $config->retention[0]->legalBasis);
        self::assertSame('sessions', $config->retention[1]->category);
        self::assertSame(30, $config->retention[1]->retentionDays);
        self::assertSame('', $config->retention[1]->legalBasis);

        self::assertSame(500, $config->purge->batchSize);
        self::assertFalse($config->purge->auditPurgeOperations);
        self::assertTrue($config->purge->dryRun);

        self::assertFalse($config->consent->requireExplicit);
        self::assertSame(['marketing', 'analytics'], $config->consent->purposes);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingKeys(): void
    {
        $config = DataProtectionConfig::fromArray([]);

        self::assertSame([], $config->retention);
        self::assertSame(1000, $config->purge->batchSize);
        self::assertTrue($config->purge->auditPurgeOperations);
        self::assertFalse($config->purge->dryRun);
        self::assertTrue($config->consent->requireExplicit);
        self::assertSame([], $config->consent->purposes);
    }

    #[Test]
    public function fromArrayHandlesNonArrayRetention(): void
    {
        $config = DataProtectionConfig::fromArray([
            'retention' => 'invalid',
        ]);

        self::assertSame([], $config->retention);
    }

    #[Test]
    public function fromArraySkipsNonArrayRetentionEntries(): void
    {
        $config = DataProtectionConfig::fromArray([
            'retention' => [
                'not_an_array',
                ['category' => 'valid', 'retention_days' => 7],
                42,
            ],
        ]);

        self::assertCount(1, $config->retention);
        self::assertSame('valid', $config->retention[0]->category);
    }

    #[Test]
    public function fromArrayHandlesNonArrayPurgeAndConsent(): void
    {
        $config = DataProtectionConfig::fromArray([
            'purge' => 'invalid',
            'consent' => 123,
        ]);

        self::assertSame(1000, $config->purge->batchSize);
        self::assertTrue($config->consent->requireExplicit);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('retentionPolicyAcceptedValueProvider')]
    public function retentionPolicyFromArrayAcceptsUsableValues(
        array $input,
        string $expectedCategory,
        int $expectedDays,
        string $expectedBasis,
    ): void {
        $policy = RetentionPolicy::fromArray($input);

        self::assertSame($expectedCategory, $policy->category);
        self::assertSame($expectedDays, $policy->retentionDays);
        self::assertSame($expectedBasis, $policy->legalBasis);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, int, string}>
     */
    public static function retentionPolicyAcceptedValueProvider(): iterable
    {
        yield 'plain int' => [
            ['category' => 'logs', 'retention_days' => 30, 'legal_basis' => 'test'],
            'logs',
            30,
            'test',
        ];

        // env() returns every value as a string, so "90" must mean 90 days —
        // reading it as 0 would silently mean "retain forever".
        yield 'numeric string from env()' => [
            ['category' => 'logs', 'retention_days' => '90', 'legal_basis' => 'test'],
            'logs',
            90,
            'test',
        ];

        yield 'explicit zero means indefinite' => [
            ['category' => 'logs', 'retention_days' => 0],
            'logs',
            0,
            '',
        ];

        yield 'absent retention_days defaults to indefinite' => [
            ['category' => 'logs'],
            'logs',
            0,
            '',
        ];

        yield 'non-string legal_basis falls back (documentation only)' => [
            ['category' => 'logs', 'retention_days' => 30, 'legal_basis' => 99],
            'logs',
            30,
            '',
        ];
    }

    /**
     * A malformed retention entry must be REFUSED, never silently defaulted: every
     * silent fallback here means "retain forever" (0 = indefinite; an unknown
     * category matches no purger), which turns an operator typo into a
     * storage-limitation violation for personal data.
     *
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('retentionPolicyRejectedValueProvider')]
    public function retentionPolicyFromArrayRefusesMalformedValues(array $input, string $expectedMessageFragment): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessageFragment, '/') . '/');

        (void) RetentionPolicy::fromArray($input);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function retentionPolicyRejectedValueProvider(): iterable
    {
        yield 'non-string category' => [
            ['category' => 42, 'retention_days' => 30],
            'must name the category',
        ];

        yield 'missing category' => [
            ['retention_days' => 30],
            'must name the category',
        ];

        yield 'unparseable retention_days' => [
            ['category' => 'logs', 'retention_days' => 'ninety'],
            'expected a number of days',
        ];

        yield 'negative retention_days' => [
            ['category' => 'logs', 'retention_days' => -1],
            'negative retention period',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('purgeConfigTypeCoercionProvider')]
    public function purgeConfigFromArrayCoercesInvalidTypes(
        array $input,
        int $expectedBatchSize,
        bool $expectedAudit,
        bool $expectedDryRun,
    ): void {
        $config = PurgeConfig::fromArray($input);

        self::assertSame($expectedBatchSize, $config->batchSize);
        self::assertSame($expectedAudit, $config->auditPurgeOperations);
        self::assertSame($expectedDryRun, $config->dryRun);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, bool, bool}>
     */
    public static function purgeConfigTypeCoercionProvider(): iterable
    {
        yield 'non-int batch_size defaults to 1000' => [
            ['batch_size' => 'large'],
            1000,
            true,
            false,
        ];

        yield 'non-bool audit defaults to true' => [
            ['audit_purge_operations' => 'yes'],
            1000,
            true,
            false,
        ];

        yield 'non-bool dry_run defaults to false' => [
            ['dry_run' => 1],
            1000,
            true,
            false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $expectedPurposes
     */
    #[Test]
    #[DataProvider('consentConfigTypeCoercionProvider')]
    public function consentConfigFromArrayCoercesInvalidTypes(
        array $input,
        bool $expectedExplicit,
        array $expectedPurposes,
    ): void {
        $config = ConsentConfig::fromArray($input);

        self::assertSame($expectedExplicit, $config->requireExplicit);
        self::assertSame($expectedPurposes, $config->purposes);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, list<string>}>
     */
    public static function consentConfigTypeCoercionProvider(): iterable
    {
        yield 'non-bool require_explicit defaults to true' => [
            ['require_explicit' => 'yes'],
            true,
            [],
        ];

        yield 'non-array purposes defaults to empty' => [
            ['purposes' => 'marketing'],
            true,
            [],
        ];
    }

    #[Test]
    public function defaultConstructorValues(): void
    {
        $config = new DataProtectionConfig();

        self::assertSame([], $config->retention);
        self::assertSame(1000, $config->purge->batchSize);
        self::assertTrue($config->purge->auditPurgeOperations);
        self::assertFalse($config->purge->dryRun);
        self::assertTrue($config->consent->requireExplicit);
        self::assertSame([], $config->consent->purposes);
    }
}
