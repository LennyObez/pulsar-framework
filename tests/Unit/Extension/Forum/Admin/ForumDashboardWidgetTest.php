<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Admin\Contracts\WidgetInterface;
use Pulsar\Extension\Forum\Admin\ForumDashboardWidget;

#[CoversClass(ForumDashboardWidget::class)]
final class ForumDashboardWidgetTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
    }

    #[Test]
    public function implementsWidgetInterface(): void
    {
        $widget = new ForumDashboardWidget($this->connection);
        self::assertInstanceOf(WidgetInterface::class, $widget);
    }

    #[Test]
    public function idReturnsForumOverview(): void
    {
        $widget = new ForumDashboardWidget($this->connection);
        self::assertSame('forum_overview', $widget->id());
    }

    #[Test]
    public function labelReturnsForumOverview(): void
    {
        $widget = new ForumDashboardWidget($this->connection);
        self::assertSame('Forum Overview', $widget->label());
    }

    #[Test]
    public function sizeReturnsMedium(): void
    {
        $widget = new ForumDashboardWidget($this->connection);
        self::assertSame('medium', $widget->size());
    }

    #[Test]
    public function renderReturnsExpectedKeys(): void
    {
        $countRow = new Row(['cnt' => 42]);
        $countResult = new Result([$countRow]);

        $this->connection->method('query')->willReturn($countResult);

        $widget = new ForumDashboardWidget($this->connection);
        $data = $widget->render();

        self::assertArrayHasKey('thread_count', $data);
        self::assertArrayHasKey('post_count', $data);
        self::assertArrayHasKey('pending_reports', $data);
        self::assertArrayHasKey('active_users_24h', $data);
    }

    #[Test]
    public function renderReturnsCorrectCountValues(): void
    {
        $this->connection->method('query')->willReturnCallback(
            static function (string $sql): Result {
                if (str_contains($sql, 'forum_threads')) {
                    return new Result([new Row(['cnt' => 10])]);
                }
                if (str_contains($sql, 'forum_posts') && !str_contains($sql, 'DISTINCT')) {
                    return new Result([new Row(['cnt' => 50])]);
                }
                if (str_contains($sql, 'forum_thread_reports')) {
                    return new Result([new Row(['cnt' => 3])]);
                }
                if (str_contains($sql, 'forum_post_reports')) {
                    return new Result([new Row(['cnt' => 2])]);
                }
                if (str_contains($sql, 'DISTINCT')) {
                    return new Result([new Row(['cnt' => 7])]);
                }

                return new Result([]);
            },
        );

        $widget = new ForumDashboardWidget($this->connection);
        $data = $widget->render();

        self::assertSame(10, $data['thread_count']);
        self::assertSame(50, $data['post_count']);
        self::assertSame(5, $data['pending_reports']); // 3 + 2
        self::assertSame(7, $data['active_users_24h']);
    }

    #[Test]
    public function renderHandlesEmptyResults(): void
    {
        $emptyResult = new Result([]);
        $this->connection->method('query')->willReturn($emptyResult);

        $widget = new ForumDashboardWidget($this->connection);
        $data = $widget->render();

        self::assertSame(0, $data['thread_count']);
        self::assertSame(0, $data['post_count']);
        self::assertSame(0, $data['pending_reports']);
        self::assertSame(0, $data['active_users_24h']);
    }
}
