<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Dashboard\QuickActionsWidget;

#[CoversClass(QuickActionsWidget::class)]
final class QuickActionsWidgetTest extends TestCase
{
    #[Test]
    public function getNameReturnsQuickActions(): void
    {
        $widget = new QuickActionsWidget();

        self::assertSame('quick_actions', $widget->getName());
    }

    #[Test]
    public function getTemplateReturnsExpectedPath(): void
    {
        $widget = new QuickActionsWidget();

        self::assertSame('dashboard/widgets/quick-actions', $widget->getTemplate());
    }

    #[Test]
    public function getDataReturnsFourActions(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        self::assertCount(4, $actions);
    }

    #[Test]
    public function getDataContainsNewArticleAction(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        $labels = array_column($actions, 'label');
        self::assertContains('New Article', $labels);
    }

    #[Test]
    public function getDataContainsNewPageAction(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        $labels = array_column($actions, 'label');
        self::assertContains('New Page', $labels);
    }

    #[Test]
    public function getDataContainsUploadMediaAction(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        $labels = array_column($actions, 'label');
        self::assertContains('Upload Media', $labels);
    }

    #[Test]
    public function getDataContainsViewSiteAction(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        $labels = array_column($actions, 'label');
        self::assertContains('View Site', $labels);
    }

    #[Test]
    public function viewSiteUsesCustomUrl(): void
    {
        $widget = new QuickActionsWidget('https://example.com');
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        $viewSite = null;
        foreach ($actions as $action) {
            self::assertIsArray($action);
            if ($action['label'] === 'View Site') {
                $viewSite = $action;

                break;
            }
        }

        self::assertNotNull($viewSite);
        self::assertSame('https://example.com', $viewSite['url']);
        self::assertSame('_blank', $viewSite['target']);
    }

    #[Test]
    public function eachActionHasLabelUrlAndIcon(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        foreach ($actions as $action) {
            self::assertIsArray($action);
            self::assertArrayHasKey('label', $action);
            self::assertArrayHasKey('url', $action);
            self::assertArrayHasKey('icon', $action);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function extractActions(array $data): array
    {
        self::assertArrayHasKey('actions', $data);
        $actions = $data['actions'];
        self::assertIsArray($actions);

        /** @var list<array<string, mixed>> */
        return $actions;
    }
}
