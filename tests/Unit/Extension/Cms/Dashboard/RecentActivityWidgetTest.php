<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Dashboard;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Dashboard\AuditQueryInterface;
use Pulsar\Extension\Cms\Dashboard\RecentActivityWidget;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(RecentActivityWidget::class)]
final class RecentActivityWidgetTest extends TestCase
{
    #[Test]
    public function test_get_name_returns_recent_activity(): void
    {
        $query = $this->createMock(AuditQueryInterface::class);
        $widget = new RecentActivityWidget($query);

        self::assertSame('recent_activity', $widget->getName());
    }

    #[Test]
    public function test_get_template_returns_expected_path(): void
    {
        $query = $this->createMock(AuditQueryInterface::class);
        $widget = new RecentActivityWidget($query);

        self::assertSame('dashboard/widgets/recent-activity', $widget->getTemplate());
    }

    #[Test]
    public function test_get_data_returns_empty_entries_when_none(): void
    {
        $query = $this->createMock(AuditQueryInterface::class);
        $query->method('getRecent')->willReturn([]);

        $widget = new RecentActivityWidget($query);
        $data = $widget->getData();

        self::assertSame([], $data['entries']);
    }

    #[Test]
    public function test_get_data_maps_audit_entries_to_serialized_format(): void
    {
        $timestamp = new DateTimeImmutable('2026-02-19T12:00:00+00:00');
        $entry = new AuditEntry(
            id: 'audit-001',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'user-123',
            action: 'cms.content.published',
            resource: 'content-456',
            timestamp: $timestamp,
            metadata: [],
            previousHmac: '',
            hmac: 'test-hmac',
        );

        $query = $this->createMock(AuditQueryInterface::class);
        $query->expects(self::once())
            ->method('getRecent')
            ->with(10, 'cms.')
            ->willReturn([$entry]);

        $widget = new RecentActivityWidget($query);
        $data = $widget->getData();

        /** @var list<array<string, mixed>> $entries */
        $entries = $data['entries'];
        self::assertCount(1, $entries);
        self::assertSame('audit-001', $entries[0]['id']);
        self::assertSame('data_modification', $entries[0]['event']);
        self::assertSame('success', $entries[0]['outcome']);
        self::assertSame('user-123', $entries[0]['actor']);
        self::assertSame('cms.content.published', $entries[0]['action']);
        self::assertSame('content-456', $entries[0]['resource']);
        self::assertSame('2026-02-19T12:00:00+00:00', $entries[0]['timestamp']);
    }

    #[Test]
    public function test_get_data_queries_with_cms_action_prefix(): void
    {
        $query = $this->createMock(AuditQueryInterface::class);
        $query->expects(self::once())
            ->method('getRecent')
            ->with(10, 'cms.')
            ->willReturn([]);

        $widget = new RecentActivityWidget($query);
        $widget->getData();
    }
}
