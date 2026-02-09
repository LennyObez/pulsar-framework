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
    public function test_get_name_returns_quick_actions(): void
    {
        $widget = new QuickActionsWidget();

        self::assertSame('quick_actions', $widget->getName());
    }

    #[Test]
    public function test_get_template_returns_expected_path(): void
    {
        $widget = new QuickActionsWidget();

        self::assertSame('dashboard/widgets/quick-actions', $widget->getTemplate());
    }

    #[Test]
    public function test_get_data_returns_four_actions(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        self::assertCount(4, $actions);
    }

    #[Test]
    public function test_get_data_contains_new_article_action(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        $labels = array_column($actions, 'label');
        self::assertContains('New Article', $labels);
    }

    #[Test]
    public function test_get_data_contains_new_page_action(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        $labels = array_column($actions, 'label');
        self::assertContains('New Page', $labels);
    }

    #[Test]
    public function test_get_data_contains_upload_media_action(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        $labels = array_column($actions, 'label');
        self::assertContains('Upload Media', $labels);
    }

    #[Test]
    public function test_get_data_contains_view_site_action(): void
    {
        $widget = new QuickActionsWidget();
        $data = $widget->getData();
        $actions = $this->extractActions($data);

        $labels = array_column($actions, 'label');
        self::assertContains('View Site', $labels);
    }

    #[Test]
    public function test_view_site_uses_custom_url(): void
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
    public function test_each_action_has_label_url_and_icon(): void
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
