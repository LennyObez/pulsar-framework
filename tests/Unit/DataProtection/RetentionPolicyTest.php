<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Exception\ConfigException;
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
    public function fromArrayRefusesAnEntryWithoutACategory(): void
    {
        // A retention policy with no category is inert: it matches no purger, so
        // the records it was meant to govern are never purged.
        $this->expectException(ConfigException::class);

        (void) RetentionPolicy::fromArray([]);
    }

    #[Test]
    public function fromArrayDefaultsAbsentRetentionDaysToIndefinite(): void
    {
        // Absent (unlike malformed) is a documented, deliberate spelling of
        // "retain indefinitely".
        $policy = RetentionPolicy::fromArray(['category' => 'audit_logs']);

        self::assertSame('audit_logs', $policy->category);
        self::assertSame(0, $policy->retentionDays);
        self::assertSame('', $policy->legalBasis);
    }

    #[Test]
    public function fromArrayRefusesInvalidTypes(): void
    {
        // Silently coercing these would produce a policy with a category no purger
        // matches and 0 days (= indefinite), i.e. data retained forever because of
        // a typo. The malformed entry is refused instead.
        $this->expectException(ConfigException::class);

        (void) RetentionPolicy::fromArray([
            'category' => 42,
            'retention_days' => 'not_a_number',
            'legal_basis' => false,
        ]);
    }

    #[Test]
    public function fromArrayAcceptsNumericStringsBecauseEnvReturnsStrings(): void
    {
        // 'retention_days' => env('RETENTION_DAYS', 90) yields the STRING "90";
        // reading it as 0 would silently mean indefinite retention.
        $policy = RetentionPolicy::fromArray([
            'category' => 'user_sessions',
            'retention_days' => '90',
        ]);

        self::assertSame(90, $policy->retentionDays);
    }
}
