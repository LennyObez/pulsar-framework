<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Dashboard\DashboardWidgetInterface;
use Pulsar\Extension\Forum\Cms\ForumCmsDashboardWidget;

#[CoversClass(ForumCmsDashboardWidget::class)]
final class ForumCmsDashboardWidgetTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
    }

    #[Test]
    public function implementsDashboardWidgetInterface(): void
    {
        $widget = new ForumCmsDashboardWidget($this->connection);
        self::assertInstanceOf(DashboardWidgetInterface::class, $widget);
    }

    #[Test]
    public function getNameReturnsForumOverview(): void
    {
        $widget = new ForumCmsDashboardWidget($this->connection);
        self::assertSame('forum_overview', $widget->getName());
    }

    #[Test]
    public function getTemplateReturnsDashboardWidgetPath(): void
    {
        $widget = new ForumCmsDashboardWidget($this->connection);
        self::assertSame('forum/dashboard-widget', $widget->getTemplate());
    }

    #[Test]
    public function getDataReturnsExpectedKeys(): void
    {
        $countResult = new Result([new Row(['cnt' => 0])]);
        $this->connection->method('query')->willReturn($countResult);

        $widget = new ForumCmsDashboardWidget($this->connection);
        $data = $widget->getData();

        self::assertArrayHasKey('thread_count', $data);
        self::assertArrayHasKey('post_count', $data);
        self::assertArrayHasKey('pending_reports', $data);
        self::assertArrayHasKey('active_users_24h', $data);
    }

    #[Test]
    public function getDataReturnsCorrectCounts(): void
    {
        $this->connection->method('query')->willReturnCallback(
            static function (string $sql): Result {
                if (str_contains($sql, 'forum_threads')) {
                    return new Result([new Row(['cnt' => 15])]);
                }
                if (str_contains($sql, 'forum_posts') && !str_contains($sql, 'DISTINCT')) {
                    return new Result([new Row(['cnt' => 100])]);
                }
                if (str_contains($sql, 'forum_thread_reports')) {
                    return new Result([new Row(['cnt' => 1])]);
                }
                if (str_contains($sql, 'forum_post_reports')) {
                    return new Result([new Row(['cnt' => 4])]);
                }
                if (str_contains($sql, 'DISTINCT')) {
                    return new Result([new Row(['cnt' => 12])]);
                }

                return new Result([]);
            },
        );

        $widget = new ForumCmsDashboardWidget($this->connection);
        $data = $widget->getData();

        self::assertSame(15, $data['thread_count']);
        self::assertSame(100, $data['post_count']);
        self::assertSame(5, $data['pending_reports']); // 1 + 4
        self::assertSame(12, $data['active_users_24h']);
    }

    #[Test]
    public function getDataHandlesEmptyResults(): void
    {
        $emptyResult = new Result([]);
        $this->connection->method('query')->willReturn($emptyResult);

        $widget = new ForumCmsDashboardWidget($this->connection);
        $data = $widget->getData();

        self::assertSame(0, $data['thread_count']);
        self::assertSame(0, $data['post_count']);
        self::assertSame(0, $data['pending_reports']);
        self::assertSame(0, $data['active_users_24h']);
    }
}
