<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Studio;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Studio\CmsAuditPanel;
use Pulsar\Extension\Cms\Internal\Studio\Dto\AuditPanelEntry;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\KeyRingInterface;

#[CoversClass(CmsAuditPanel::class)]
#[CoversClass(AuditPanelEntry::class)]
final class CmsAuditPanelTest extends TestCase
{
    private CmsAuditPanel $panel;

    protected function setUp(): void
    {
        $keyRing = $this->createStub(KeyRingInterface::class);
        $this->panel = new CmsAuditPanel(new AuditChainVerifier($keyRing));
    }

    #[Test]
    public function filtersOnlyCmsActions(): void
    {
        $entries = [
            $this->makeEntry('1', 'cms.content.create', AuditEvent::DataModification),
            $this->makeEntry('2', 'auth.login', AuditEvent::Authentication),
            $this->makeEntry('3', 'cms.media.upload', AuditEvent::DataModification),
        ];

        $result = $this->panel->getEntries($entries);

        self::assertCount(2, $result);
        self::assertSame('cms.content.create', $result[0]->action);
        self::assertSame('cms.media.upload', $result[1]->action);
    }

    #[Test]
    public function filtersByEventType(): void
    {
        $entries = [
            $this->makeEntry('1', 'cms.content.create', AuditEvent::DataModification),
            $this->makeEntry('2', 'cms.login', AuditEvent::Authentication),
            $this->makeEntry('3', 'cms.settings.update', AuditEvent::ConfigurationChange),
        ];

        $result = $this->panel->getEntries($entries, eventTypeFilter: AuditEvent::DataModification);

        self::assertCount(1, $result);
        self::assertSame('cms.content.create', $result[0]->action);
    }

    #[Test]
    public function filtersByDateRange(): void
    {
        $entries = [
            $this->makeEntry('1', 'cms.content.create', AuditEvent::DataModification, '2026-01-01'),
            $this->makeEntry('2', 'cms.content.update', AuditEvent::DataModification, '2026-01-15'),
            $this->makeEntry('3', 'cms.content.delete', AuditEvent::DataModification, '2026-02-01'),
        ];

        $from = new DateTimeImmutable('2026-01-10');
        $to = new DateTimeImmutable('2026-01-20');

        $result = $this->panel->getEntries($entries, from: $from, to: $to);

        self::assertCount(1, $result);
        self::assertSame('cms.content.update', $result[0]->action);
    }

    #[Test]
    public function returnsEmptyListWhenNoCmsEntries(): void
    {
        $entries = [
            $this->makeEntry('1', 'auth.login', AuditEvent::Authentication),
            $this->makeEntry('2', 'db.migration', AuditEvent::SystemEvent),
        ];

        $result = $this->panel->getEntries($entries);

        self::assertSame([], $result);
    }

    #[Test]
    public function entryDtoContainsEvidenceHash(): void
    {
        $entries = [
            $this->makeEntry('1', 'cms.content.create', AuditEvent::DataModification),
        ];

        $result = $this->panel->getEntries($entries);

        self::assertCount(1, $result);
        self::assertSame('hmac-1', $result[0]->evidenceHash);
    }

    #[Test]
    public function countCmsEntries(): void
    {
        $entries = [
            $this->makeEntry('1', 'cms.content.create', AuditEvent::DataModification),
            $this->makeEntry('2', 'auth.login', AuditEvent::Authentication),
            $this->makeEntry('3', 'cms.media.upload', AuditEvent::DataModification),
            $this->makeEntry('4', 'cms.settings.update', AuditEvent::ConfigurationChange),
        ];

        self::assertSame(3, $this->panel->countCmsEntries($entries));
    }

    #[Test]
    public function combinedEventTypeAndDateFilter(): void
    {
        $entries = [
            $this->makeEntry('1', 'cms.content.create', AuditEvent::DataModification, '2026-01-01'),
            $this->makeEntry('2', 'cms.login', AuditEvent::Authentication, '2026-01-15'),
            $this->makeEntry('3', 'cms.settings.update', AuditEvent::ConfigurationChange, '2026-01-15'),
        ];

        $from = new DateTimeImmutable('2026-01-10');
        $to = new DateTimeImmutable('2026-01-20');

        $result = $this->panel->getEntries(
            $entries,
            eventTypeFilter: AuditEvent::Authentication,
            from: $from,
            to: $to,
        );

        self::assertCount(1, $result);
        self::assertSame('cms.login', $result[0]->action);
    }

    #[Test]
    public function entryDtoMapsAllFieldsCorrectly(): void
    {
        $entry = $this->makeEntry(
            id: 'entry-42',
            action: 'cms.content.publish',
            event: AuditEvent::DataModification,
            date: '2026-02-01',
        );

        $result = $this->panel->getEntries([$entry]);

        self::assertCount(1, $result);
        $dto = $result[0];
        self::assertSame('entry-42', $dto->id);
        self::assertSame('data_modification', $dto->eventType);
        self::assertSame('success', $dto->outcome);
        self::assertSame('test-user', $dto->actor);
        self::assertSame('cms.content.publish', $dto->action);
        self::assertSame('resource-1', $dto->resource);
    }

    private function makeEntry(
        string $id,
        string $action,
        AuditEvent $event,
        string $date = '2026-01-15',
    ): AuditEntry {
        return new AuditEntry(
            id: $id,
            event: $event,
            outcome: AuditOutcome::Success,
            actor: 'test-user',
            action: $action,
            resource: 'resource-1',
            timestamp: new DateTimeImmutable($date),
            metadata: [],
            previousHmac: 'prev-hmac',
            hmac: 'hmac-' . $id,
            kid: 'kid-1',
        );
    }
}
