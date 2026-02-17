<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\ConsentRecord;
use Pulsar\DataProtection\InMemoryConsentManager;

#[CoversClass(InMemoryConsentManager::class)]
final class InMemoryConsentManagerTest extends TestCase
{
    private InMemoryConsentManager $manager;

    protected function setUp(): void
    {
        $this->manager = new InMemoryConsentManager();
    }

    #[Test]
    public function grantCreatesActiveConsentRecord(): void
    {
        $record = $this->manager->grant('user-1', 'marketing_email', 'v1.0');

        self::assertInstanceOf(ConsentRecord::class, $record);
        self::assertSame('user-1', $record->subjectId());
        self::assertSame('marketing_email', $record->purpose());
        self::assertTrue($record->isGranted());
        self::assertSame('v1.0', $record->policyVersion());
    }

    #[Test]
    public function hasConsentReturnsTrueAfterGrant(): void
    {
        $this->manager->grant('user-1', 'analytics');

        self::assertTrue($this->manager->hasConsent('user-1', 'analytics'));
    }

    #[Test]
    public function hasConsentReturnsFalseWhenNoRecordExists(): void
    {
        self::assertFalse($this->manager->hasConsent('user-1', 'analytics'));
    }

    #[Test]
    public function revokeMarksConsentAsRevoked(): void
    {
        $this->manager->grant('user-1', 'marketing_email');

        $revoked = $this->manager->revoke('user-1', 'marketing_email');

        self::assertNotNull($revoked);
        self::assertFalse($revoked->isGranted());
        self::assertFalse($this->manager->hasConsent('user-1', 'marketing_email'));
    }

    #[Test]
    public function revokeReturnsNullWhenNoRecordExists(): void
    {
        self::assertNull($this->manager->revoke('user-1', 'nonexistent'));
    }

    #[Test]
    public function revokePreservesPolicyVersion(): void
    {
        $this->manager->grant('user-1', 'analytics', 'v2.0');

        $revoked = $this->manager->revoke('user-1', 'analytics');

        self::assertNotNull($revoked);
        self::assertSame('v2.0', $revoked->policyVersion());
    }

    #[Test]
    public function getRecordReturnsLatestRecord(): void
    {
        $this->manager->grant('user-1', 'marketing_email', 'v1.0');

        $record = $this->manager->getRecord('user-1', 'marketing_email');

        self::assertNotNull($record);
        self::assertSame('user-1', $record->subjectId());
        self::assertSame('marketing_email', $record->purpose());
    }

    #[Test]
    public function getRecordReturnsNullWhenNoRecordExists(): void
    {
        self::assertNull($this->manager->getRecord('user-1', 'nonexistent'));
    }

    #[Test]
    public function getRecordReturnsRevokedRecordAfterRevoke(): void
    {
        $this->manager->grant('user-1', 'analytics');
        $this->manager->revoke('user-1', 'analytics');

        $record = $this->manager->getRecord('user-1', 'analytics');

        self::assertNotNull($record);
        self::assertFalse($record->isGranted());
    }

    #[Test]
    public function grantSupersedesExistingRecord(): void
    {
        $this->manager->grant('user-1', 'marketing_email', 'v1.0');
        $updated = $this->manager->grant('user-1', 'marketing_email', 'v2.0');

        self::assertSame('v2.0', $updated->policyVersion());

        $record = $this->manager->getRecord('user-1', 'marketing_email');
        self::assertNotNull($record);
        self::assertSame('v2.0', $record->policyVersion());
        self::assertTrue($record->isGranted());
    }

    #[Test]
    public function reGrantAfterRevokeRestoresConsent(): void
    {
        $this->manager->grant('user-1', 'analytics');
        $this->manager->revoke('user-1', 'analytics');
        $this->manager->grant('user-1', 'analytics', 'v3.0');

        self::assertTrue($this->manager->hasConsent('user-1', 'analytics'));
    }

    #[Test]
    public function getAllForSubjectReturnsAllPurposes(): void
    {
        $this->manager->grant('user-1', 'marketing_email');
        $this->manager->grant('user-1', 'analytics');
        $this->manager->grant('user-1', 'data_sharing');

        $records = $this->manager->getAllForSubject('user-1');

        self::assertCount(3, $records);

        $purposes = array_map(static fn($r) => $r->purpose(), $records);
        self::assertContains('marketing_email', $purposes);
        self::assertContains('analytics', $purposes);
        self::assertContains('data_sharing', $purposes);
    }

    #[Test]
    public function getAllForSubjectReturnsEmptyForUnknownSubject(): void
    {
        self::assertSame([], $this->manager->getAllForSubject('unknown'));
    }

    #[Test]
    public function getAllForSubjectDoesNotIncludeOtherSubjects(): void
    {
        $this->manager->grant('user-1', 'analytics');
        $this->manager->grant('user-2', 'marketing_email');

        $records = $this->manager->getAllForSubject('user-1');

        self::assertCount(1, $records);
        self::assertSame('analytics', $records[0]->purpose());
    }

    #[Test]
    public function getAllForSubjectIncludesRevokedRecords(): void
    {
        $this->manager->grant('user-1', 'analytics');
        $this->manager->revoke('user-1', 'analytics');

        $records = $this->manager->getAllForSubject('user-1');

        self::assertCount(1, $records);
        self::assertFalse($records[0]->isGranted());
    }

    #[Test]
    public function differentSubjectsSamePurposeAreIndependent(): void
    {
        $this->manager->grant('user-1', 'analytics');
        $this->manager->grant('user-2', 'analytics');

        self::assertTrue($this->manager->hasConsent('user-1', 'analytics'));
        self::assertTrue($this->manager->hasConsent('user-2', 'analytics'));

        $this->manager->revoke('user-1', 'analytics');

        self::assertFalse($this->manager->hasConsent('user-1', 'analytics'));
        self::assertTrue($this->manager->hasConsent('user-2', 'analytics'));
    }

    #[Test]
    public function grantDefaultsPolicyVersionToEmptyString(): void
    {
        $record = $this->manager->grant('user-1', 'analytics');

        self::assertSame('', $record->policyVersion());
    }
}
