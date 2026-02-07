<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Retention;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Retention\RetentionPolicy;
use Pulsar\Security\Compliance\Retention\RetentionSchedule;

#[CoversClass(RetentionSchedule::class)]
final class RetentionScheduleTest extends TestCase
{
    #[Test]
    public function policyForReturnsMatchingPolicy(): void
    {
        $soxPolicy = new RetentionPolicy(
            policyId: 'sox-1',
            version: 1,
            regulation: 'SOX',
            retentionPeriodDays: 2555,
            effectiveDate: new DateTimeImmutable('2024-01-01'),
            description: 'SOX retention',
        );

        $schedule = new RetentionSchedule([$soxPolicy]);

        $result = $schedule->policyFor('SOX');

        self::assertNotNull($result);
        self::assertSame('sox-1', $result->policyId);
    }

    #[Test]
    public function policyForReturnsNullForUnknownRegulation(): void
    {
        $schedule = new RetentionSchedule([]);

        $result = $schedule->policyFor('UNKNOWN');

        self::assertNull($result);
    }

    #[Test]
    public function allReturnsPoliciesList(): void
    {
        $sox = new RetentionPolicy(
            policyId: 'sox-1',
            version: 1,
            regulation: 'SOX',
            retentionPeriodDays: 2555,
            effectiveDate: new DateTimeImmutable('2024-01-01'),
            description: 'SOX',
        );
        $hipaa = new RetentionPolicy(
            policyId: 'hipaa-1',
            version: 1,
            regulation: 'HIPAA',
            retentionPeriodDays: 2190,
            effectiveDate: new DateTimeImmutable('2024-01-01'),
            description: 'HIPAA',
        );

        $schedule = new RetentionSchedule([$sox, $hipaa]);

        $all = $schedule->all();

        self::assertCount(2, $all);
    }

    #[Test]
    public function defaultScheduleContainsSox(): void
    {
        $schedule = RetentionSchedule::default();

        $sox = $schedule->policyFor('SOX');

        self::assertNotNull($sox);
        self::assertSame(2555, $sox->retentionPeriodDays);
    }

    #[Test]
    public function defaultScheduleContainsHipaa(): void
    {
        $schedule = RetentionSchedule::default();

        $hipaa = $schedule->policyFor('HIPAA');

        self::assertNotNull($hipaa);
        self::assertSame(2190, $hipaa->retentionPeriodDays);
    }

    #[Test]
    public function defaultScheduleContainsPciDss(): void
    {
        $schedule = RetentionSchedule::default();

        $pci = $schedule->policyFor('PCI-DSS');

        self::assertNotNull($pci);
        self::assertSame(365, $pci->retentionPeriodDays);
    }

    #[Test]
    public function defaultScheduleContainsGdpr(): void
    {
        $schedule = RetentionSchedule::default();

        $gdpr = $schedule->policyFor('GDPR');

        self::assertNotNull($gdpr);
        self::assertSame(365, $gdpr->retentionPeriodDays);
    }

    #[Test]
    public function defaultScheduleContainsDora(): void
    {
        $schedule = RetentionSchedule::default();

        $dora = $schedule->policyFor('DORA');

        self::assertNotNull($dora);
        self::assertSame(1825, $dora->retentionPeriodDays);
    }

    #[Test]
    public function defaultScheduleContainsAml(): void
    {
        $schedule = RetentionSchedule::default();

        $aml = $schedule->policyFor('AML');

        self::assertNotNull($aml);
        self::assertSame(1825, $aml->retentionPeriodDays);
    }

    #[Test]
    public function defaultScheduleContainsSixPolicies(): void
    {
        $schedule = RetentionSchedule::default();

        self::assertCount(6, $schedule->all());
    }

    #[Test]
    public function policyForIsCaseInsensitive(): void
    {
        $schedule = RetentionSchedule::default();

        self::assertNotNull($schedule->policyFor('sox'));
        self::assertNotNull($schedule->policyFor('SOX'));
        self::assertNotNull($schedule->policyFor('Sox'));
    }

    #[Test]
    public function policyForNormalizesHyphensToUnderscores(): void
    {
        $schedule = RetentionSchedule::default();

        $pciFromHyphen = $schedule->policyFor('PCI-DSS');
        $pciFromUnderscore = $schedule->policyFor('pci_dss');

        self::assertNotNull($pciFromHyphen);
        self::assertNotNull($pciFromUnderscore);
        self::assertSame($pciFromHyphen->policyId, $pciFromUnderscore->policyId);
    }

    #[Test]
    public function laterPolicyOverridesEarlierForSameRegulation(): void
    {
        $v1 = new RetentionPolicy(
            policyId: 'sox-1',
            version: 1,
            regulation: 'SOX',
            retentionPeriodDays: 2555,
            effectiveDate: new DateTimeImmutable('2024-01-01'),
            description: 'Original',
        );
        $v2 = new RetentionPolicy(
            policyId: 'sox-2',
            version: 2,
            regulation: 'SOX',
            retentionPeriodDays: 3650,
            effectiveDate: new DateTimeImmutable('2024-06-01'),
            description: 'Updated',
        );

        $schedule = new RetentionSchedule([$v1, $v2]);

        $result = $schedule->policyFor('SOX');

        self::assertNotNull($result);
        self::assertSame('sox-2', $result->policyId);
        self::assertSame(3650, $result->retentionPeriodDays);
    }
}
