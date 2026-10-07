<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\ConsentManagerInterface;
use Pulsar\DataProtection\ConsentRecordInterface;

final class ConsentManagerInterfaceTest extends TestCase
{
    #[Test]
    public function grantReturnsConsentRecord(): void
    {
        $record = $this->createStub(ConsentRecordInterface::class);
        $record->method('subjectId')->willReturn('user-1');
        $record->method('purpose')->willReturn('marketing');
        $record->method('isGranted')->willReturn(true);

        $manager = $this->createStub(ConsentManagerInterface::class);
        $manager->method('grant')->willReturn($record);

        $result = $manager->grant('user-1', 'marketing', 'v2.0');

        self::assertSame('user-1', $result->subjectId());
        self::assertSame('marketing', $result->purpose());
        self::assertTrue($result->isGranted());
    }

    #[Test]
    public function revokeReturnsNullWhenNoRecordExists(): void
    {
        $manager = $this->createStub(ConsentManagerInterface::class);
        $manager->method('revoke')->willReturn(null);

        $result = $manager->revoke('unknown-user', 'analytics');

        self::assertNull($result);
    }

    #[Test]
    public function revokeReturnsRevokedRecord(): void
    {
        $record = $this->createStub(ConsentRecordInterface::class);
        $record->method('isGranted')->willReturn(false);
        $record->method('subjectId')->willReturn('user-1');

        $manager = $this->createStub(ConsentManagerInterface::class);
        $manager->method('revoke')->willReturn($record);

        $result = $manager->revoke('user-1', 'marketing');

        self::assertNotNull($result);
        self::assertFalse($result->isGranted());
    }

    #[Test]
    public function hasConsentReturnsTrueWhenGranted(): void
    {
        $manager = $this->createStub(ConsentManagerInterface::class);
        $manager->method('hasConsent')->willReturn(true);

        self::assertTrue($manager->hasConsent('user-1', 'analytics'));
    }

    #[Test]
    public function hasConsentReturnsFalseWhenNotGranted(): void
    {
        $manager = $this->createStub(ConsentManagerInterface::class);
        $manager->method('hasConsent')->willReturn(false);

        self::assertFalse($manager->hasConsent('user-1', 'analytics'));
    }

    #[Test]
    public function getRecordReturnsNullForUnknownCombination(): void
    {
        $manager = $this->createStub(ConsentManagerInterface::class);
        $manager->method('getRecord')->willReturn(null);

        self::assertNull($manager->getRecord('user-1', 'nonexistent'));
    }

    #[Test]
    public function getRecordReturnsConsentRecord(): void
    {
        $record = $this->createStub(ConsentRecordInterface::class);
        $record->method('purpose')->willReturn('marketing');

        $manager = $this->createStub(ConsentManagerInterface::class);
        $manager->method('getRecord')->willReturn($record);

        $result = $manager->getRecord('user-1', 'marketing');

        self::assertNotNull($result);
        self::assertSame('marketing', $result->purpose());
    }

    #[Test]
    public function getAllForSubjectReturnsEmptyArrayWhenNoRecords(): void
    {
        $manager = $this->createStub(ConsentManagerInterface::class);
        $manager->method('getAllForSubject')->willReturn([]);

        self::assertSame([], $manager->getAllForSubject('unknown-user'));
    }

    #[Test]
    public function getAllForSubjectReturnsMultipleRecords(): void
    {
        $r1 = $this->createStub(ConsentRecordInterface::class);
        $r1->method('purpose')->willReturn('marketing');
        $r2 = $this->createStub(ConsentRecordInterface::class);
        $r2->method('purpose')->willReturn('analytics');

        $manager = $this->createStub(ConsentManagerInterface::class);
        $manager->method('getAllForSubject')->willReturn([$r1, $r2]);

        $results = $manager->getAllForSubject('user-1');

        self::assertCount(2, $results);
        self::assertSame('marketing', $results[0]->purpose());
        self::assertSame('analytics', $results[1]->purpose());
    }
}
