<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\Toolbar;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\Toolbar\DevToolbar;
use Pulsar\Dev\Toolbar\ToolbarData;

#[CoversClass(DevToolbar::class)]
final class DevToolbarTest extends TestCase
{
    /**
     * @param list<array{sql: string, time_ms: float}> $queries
     * @param list<string> $loadedTemplates
     */
    private function createToolbarData(
        float $requestTimeMs = 25.3,
        int $memoryPeakBytes = 2 * 1024 * 1024,
        string $phpVersion = '8.5.4',
        array $queries = [],
        int $cacheHits = 5,
        int $cacheMisses = 2,
        array $loadedTemplates = [],
        ?string $routeName = 'home',
        ?string $controller = 'HomeController::index',
    ): ToolbarData {
        return new ToolbarData(
            requestTimeMs: $requestTimeMs,
            memoryPeakBytes: $memoryPeakBytes,
            phpVersion: $phpVersion,
            queries: $queries,
            cacheHits: $cacheHits,
            cacheMisses: $cacheMisses,
            loadedTemplates: $loadedTemplates,
            routeName: $routeName,
            controller: $controller,
        );
    }

    #[Test]
    public function renderProducesToolbarHtml(): void
    {
        $toolbar = new DevToolbar();
        $html = $toolbar->render($this->createToolbarData());

        self::assertStringContainsString('pulsar-toolbar', $html);
        self::assertStringContainsString('</style>', $html);
        self::assertStringContainsString('</script>', $html);
    }

    #[Test]
    public function renderShowsRequestTime(): void
    {
        $toolbar = new DevToolbar();
        $html = $toolbar->render($this->createToolbarData(requestTimeMs: 42.1));

        self::assertStringContainsString('42.1 ms', $html);
    }

    #[Test]
    public function renderShowsMemoryUsage(): void
    {
        $toolbar = new DevToolbar();
        $html = $toolbar->render($this->createToolbarData(memoryPeakBytes: 5 * 1024 * 1024));

        self::assertStringContainsString('MB', $html);
    }

    #[Test]
    public function renderShowsPhpVersion(): void
    {
        $toolbar = new DevToolbar();
        $html = $toolbar->render($this->createToolbarData(phpVersion: '8.5.4'));

        self::assertStringContainsString('PHP 8.5.4', $html);
    }

    #[Test]
    public function renderShowsQueryCountAndTime(): void
    {
        $toolbar = new DevToolbar();
        $data = $this->createToolbarData(queries: [
            ['sql' => 'SELECT 1', 'time_ms' => 1.5],
            ['sql' => 'SELECT 2', 'time_ms' => 2.5],
        ]);

        $html = $toolbar->render($data);

        self::assertStringContainsString('DB: 2', $html);
        self::assertStringContainsString('4.0 ms', $html);
    }

    #[Test]
    public function renderShowsCacheStats(): void
    {
        $toolbar = new DevToolbar();
        $html = $toolbar->render($this->createToolbarData(cacheHits: 12, cacheMisses: 3));

        self::assertStringContainsString('12H', $html);
        self::assertStringContainsString('3M', $html);
    }

    #[Test]
    public function renderShowsTemplateCount(): void
    {
        $toolbar = new DevToolbar();
        $data = $this->createToolbarData(loadedTemplates: ['a.pulse', 'b.pulse', 'c.pulse']);

        $html = $toolbar->render($data);

        self::assertStringContainsString('TPL: 3', $html);
    }

    #[Test]
    public function renderShowsRouteName(): void
    {
        $toolbar = new DevToolbar();
        $html = $toolbar->render($this->createToolbarData(routeName: 'users.index'));

        self::assertStringContainsString('users.index', $html);
    }

    #[Test]
    public function renderShowsControllerName(): void
    {
        $toolbar = new DevToolbar();
        $html = $toolbar->render($this->createToolbarData(controller: 'UserController::index'));

        self::assertStringContainsString('UserController::index', $html);
    }

    #[Test]
    public function renderShowsNAForNullRoute(): void
    {
        $toolbar = new DevToolbar();
        $html = $toolbar->render($this->createToolbarData(routeName: null));

        self::assertStringContainsString('N/A', $html);
    }

    #[Test]
    public function renderExpandableQueriesPanel(): void
    {
        $toolbar = new DevToolbar();
        $data = $this->createToolbarData(queries: [
            ['sql' => 'SELECT * FROM users', 'time_ms' => 1.23],
        ]);

        $html = $toolbar->render($data);

        self::assertStringContainsString('pulsar-toolbar-queries', $html);
        self::assertStringContainsString('SELECT * FROM users', $html);
        self::assertStringContainsString('1.23', $html);
    }

    #[Test]
    public function renderHighlightsSlowQueries(): void
    {
        $toolbar = new DevToolbar();
        $data = $this->createToolbarData(queries: [
            ['sql' => 'SELECT * FROM huge_table', 'time_ms' => 250.0],
        ]);

        $html = $toolbar->render($data);

        self::assertStringContainsString('pulsar-toolbar__slow', $html);
    }

    #[Test]
    public function renderExpandableTemplatesPanel(): void
    {
        $toolbar = new DevToolbar();
        $data = $this->createToolbarData(loadedTemplates: ['layout.pulse', 'sidebar.pulse']);

        $html = $toolbar->render($data);

        self::assertStringContainsString('pulsar-toolbar-templates', $html);
        self::assertStringContainsString('layout.pulse', $html);
        self::assertStringContainsString('sidebar.pulse', $html);
    }

    #[Test]
    public function renderShowsEmptyMessageForNoQueries(): void
    {
        $toolbar = new DevToolbar();
        $data = $this->createToolbarData(queries: []);

        $html = $toolbar->render($data);

        self::assertStringContainsString('No queries executed', $html);
    }

    #[Test]
    public function renderShowsEmptyMessageForNoTemplates(): void
    {
        $toolbar = new DevToolbar();
        $data = $this->createToolbarData(loadedTemplates: []);

        $html = $toolbar->render($data);

        self::assertStringContainsString('No templates loaded', $html);
    }

    #[Test]
    public function renderContainsCollapseToggle(): void
    {
        $toolbar = new DevToolbar();
        $html = $toolbar->render($this->createToolbarData());

        self::assertStringContainsString('pulsarToolbarCollapse', $html);
        self::assertStringContainsString('pulsar_toolbar', $html); // cookie name
    }

    #[Test]
    public function renderEscapesHtmlInSqlQueries(): void
    {
        $toolbar = new DevToolbar();
        $data = $this->createToolbarData(queries: [
            ['sql' => "SELECT * FROM users WHERE name = '<b>injected</b>'", 'time_ms' => 1.0],
        ]);

        $html = $toolbar->render($data);

        // The SQL-embedded HTML must be escaped; the toolbar's own <script> tags are fine
        self::assertStringNotContainsString('<b>injected</b>', $html);
        self::assertStringContainsString('&lt;b&gt;injected&lt;/b&gt;', $html);
    }

    #[Test]
    public function renderEscapesHtmlInTemplatePaths(): void
    {
        $toolbar = new DevToolbar();
        $data = $this->createToolbarData(loadedTemplates: ['<img onerror=alert(1)>.pulse']);

        $html = $toolbar->render($data);

        self::assertStringNotContainsString('<img onerror', $html);
    }

    #[Test]
    public function renderContainsDesignCharterColors(): void
    {
        $toolbar = new DevToolbar();
        $html = $toolbar->render($this->createToolbarData());

        // Pulsar Deep Blue for accent border
        self::assertStringContainsString('#0039cb', $html);
        // Dark background
        self::assertStringContainsString('#0f172a', $html);
    }
}
