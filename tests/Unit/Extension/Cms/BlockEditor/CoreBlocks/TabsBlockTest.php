<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\TabsBlock;

#[CoversClass(TabsBlock::class)]
final class TabsBlockTest extends TestCase
{
    private TabsBlock $block;

    protected function setUp(): void
    {
        $this->block = new TabsBlock();
    }

    #[Test]
    public function typeReturnsTabs(): void
    {
        self::assertSame('tabs', $this->block->type());
    }

    #[Test]
    public function rendersTabsWithRequiredFieldsOnly(): void
    {
        $html = $this->block->render([
            'tabs' => [
                ['title' => 'Tab 1', 'content' => 'Content 1'],
                ['title' => 'Tab 2', 'content' => 'Content 2'],
            ],
        ]);

        self::assertStringContainsString('class="tabs"', $html);
        self::assertStringContainsString('role="tablist"', $html);
        self::assertStringContainsString('class="tabs__list"', $html);
        self::assertStringContainsString('Tab 1</button>', $html);
        self::assertStringContainsString('Content 1</div>', $html);
    }

    #[Test]
    public function rendersWithDefaultActiveTab(): void
    {
        $html = $this->block->render([
            'tabs' => [
                ['title' => 'Tab 1', 'content' => 'Content 1'],
                ['title' => 'Tab 2', 'content' => 'Content 2'],
            ],
            'defaultActive' => 1,
        ]);

        self::assertStringContainsString('id="tab-0" aria-controls="panel-0" aria-selected="false"', $html);
        self::assertStringContainsString('id="tab-1" aria-controls="panel-1" aria-selected="true"', $html);
    }

    #[Test]
    public function firstTabIsActiveByDefault(): void
    {
        $html = $this->block->render([
            'tabs' => [
                ['title' => 'Tab 1', 'content' => 'Content 1'],
                ['title' => 'Tab 2', 'content' => 'Content 2'],
            ],
        ]);

        self::assertStringContainsString('id="tab-0" aria-controls="panel-0" aria-selected="true"', $html);
        self::assertStringContainsString('id="tab-1" aria-controls="panel-1" aria-selected="false"', $html);
    }

    #[Test]
    public function rendersAriaAttributes(): void
    {
        $html = $this->block->render([
            'tabs' => [
                ['title' => 'Tab 1', 'content' => 'Content 1'],
            ],
        ]);

        self::assertStringContainsString('role="tablist"', $html);
        self::assertStringContainsString('role="tab"', $html);
        self::assertStringContainsString('role="tabpanel"', $html);
        self::assertStringContainsString('aria-controls="panel-0"', $html);
        self::assertStringContainsString('aria-labelledby="tab-0"', $html);
        self::assertStringContainsString('aria-selected="true"', $html);
    }

    #[Test]
    public function hiddenAttributeOnNonActivePanels(): void
    {
        $html = $this->block->render([
            'tabs' => [
                ['title' => 'Tab 1', 'content' => 'Content 1'],
                ['title' => 'Tab 2', 'content' => 'Content 2'],
            ],
        ]);

        self::assertStringContainsString('id="panel-0" aria-labelledby="tab-0" class="tabs__panel">Content 1</div>', $html);
        self::assertStringContainsString('id="panel-1" aria-labelledby="tab-1" class="tabs__panel" hidden>Content 2</div>', $html);
    }

    #[Test]
    public function escapesXssInTitleAndContent(): void
    {
        $html = $this->block->render([
            'tabs' => [
                ['title' => '<script>xss</script>', 'content' => '<img onerror="hack">'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('onerror="hack"', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('onerror=&quot;hack&quot;', $html);
    }

    #[Test]
    public function validatesRequiredTabs(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('tabs is required and must be an array', $errors);
    }

    #[Test]
    public function validatesTabsMustBeArray(): void
    {
        $errors = $this->block->validate(['tabs' => 'not-an-array']);

        self::assertContains('tabs is required and must be an array', $errors);
    }

    #[Test]
    public function validatesTabTitleRequired(): void
    {
        $errors = $this->block->validate([
            'tabs' => [['content' => 'Body']],
        ]);

        self::assertContains('tabs[0].title is required and must be a string', $errors);
    }

    #[Test]
    public function validatesTabContentRequired(): void
    {
        $errors = $this->block->validate([
            'tabs' => [['title' => 'Tab']],
        ]);

        self::assertContains('tabs[0].content is required and must be a string', $errors);
    }

    #[Test]
    public function validatesDefaultActiveNonNegative(): void
    {
        $errors = $this->block->validate([
            'tabs' => [['title' => 'Tab', 'content' => 'Body']],
            'defaultActive' => -1,
        ]);

        self::assertContains('defaultActive must be a non-negative integer', $errors);
    }

    #[Test]
    public function validatesDefaultActiveLessThanTabCount(): void
    {
        $errors = $this->block->validate([
            'tabs' => [['title' => 'Tab', 'content' => 'Body']],
            'defaultActive' => 1,
        ]);

        self::assertContains('defaultActive must be less than the number of tabs', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'tabs' => [
                ['title' => 'Tab 1', 'content' => 'Content 1'],
                ['title' => 'Tab 2', 'content' => 'Content 2'],
            ],
            'defaultActive' => 1,
        ]);

        self::assertSame([], $errors);
    }
}
