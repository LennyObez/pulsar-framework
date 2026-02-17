<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Server\View\ViewRenderer;
use RuntimeException;

final class ViewRendererTest extends TestCase
{
    private string $templateDir;

    /** @var list<string> Absolute paths of files created during the test */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        $this->templateDir = sys_get_temp_dir() . '/pulsar_studio_view_test_' . bin2hex(random_bytes(4));
        mkdir($this->templateDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        // Delete only files we explicitly created, verified via realpath
        foreach ($this->createdFiles as $absolutePath) {
            if (file_exists($absolutePath)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                unlink($absolutePath);
            }
        }
        $this->createdFiles = [];

        if (is_dir($this->templateDir)) {
            @rmdir($this->templateDir);
        }
    }

    #[Test]
    public function render_template_with_data(): void
    {
        $this->writeTemplate('simple.php', '<?php echo "Hello, " . htmlspecialchars($name); ?>');

        $renderer = new ViewRenderer($this->templateDir);
        $output = $renderer->render('simple', ['name' => 'World']);

        self::assertSame('Hello, World', $output);
    }

    #[Test]
    public function render_throws_on_missing_template(): void
    {
        $renderer = new ViewRenderer($this->templateDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Template not found');

        $renderer->render('nonexistent');
    }

    #[Test]
    public function render_with_layout(): void
    {
        $this->writeTemplate('layout.php', '<html><body><?php echo $content; ?></body></html>');
        $this->writeTemplate('page.php', '<p><?php echo $message; ?></p>');

        $renderer = new ViewRenderer($this->templateDir);
        $output = $renderer->renderWithLayout('page', ['message' => 'Test'], 'My Page');

        self::assertStringContainsString('<html>', $output);
        self::assertStringContainsString('<p>Test</p>', $output);
    }

    #[Test]
    public function render_with_empty_data(): void
    {
        $this->writeTemplate('empty.php', 'static content');

        $renderer = new ViewRenderer($this->templateDir);
        $output = $renderer->render('empty');

        self::assertSame('static content', $output);
    }

    #[Test]
    public function render_escapes_properly_in_template(): void
    {
        $this->writeTemplate('escaped.php', '<?php echo htmlspecialchars($content, ENT_QUOTES); ?>');

        $renderer = new ViewRenderer($this->templateDir);
        $output = $renderer->render('escaped', ['content' => '<script>alert("xss")</script>']);

        self::assertStringNotContainsString('<script>', $output);
        self::assertStringContainsString('&lt;script&gt;', $output);
    }

    #[Test]
    public function render_propagates_template_exception(): void
    {
        $this->writeTemplate('throws.php', '<?php throw new \RuntimeException("Template error"); ?>');

        $renderer = new ViewRenderer($this->templateDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Template error');

        $renderer->render('throws');
    }

    /**
     * Write a template file and track it for cleanup.
     *
     * Validates that the resolved path stays inside the template directory
     * to prevent path traversal, then records the absolute path for tearDown.
     */
    private function writeTemplate(string $filename, string $content): void
    {
        $targetPath = $this->templateDir . '/' . $filename;
        file_put_contents($targetPath, $content);

        $resolved = realpath($targetPath);
        $dirResolved = realpath($this->templateDir);

        if ($resolved !== false && $dirResolved !== false && str_starts_with($resolved, $dirResolved)) {
            $this->createdFiles[] = $resolved;
        }
    }
}
