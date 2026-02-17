<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\ErrorRenderer;
use Pulsar\Console\Repl\SyntaxHighlighter;
use RuntimeException;

#[CoversClass(ErrorRenderer::class)]
final class ErrorRendererTest extends TestCase
{
    private ErrorRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ErrorRenderer(colorsEnabled: true);
    }

    #[Test]
    public function renderShowsExceptionClass(): void
    {
        $exception = new RuntimeException('Something went wrong');
        $output = $this->renderer->render($exception);

        $stripped = SyntaxHighlighter::stripAnsi($output);
        self::assertStringContainsString('RuntimeException', $stripped);
    }

    #[Test]
    public function renderShowsExceptionMessage(): void
    {
        $exception = new RuntimeException('Database connection failed');
        $output = $this->renderer->render($exception);

        $stripped = SyntaxHighlighter::stripAnsi($output);
        self::assertStringContainsString('Database connection failed', $stripped);
    }

    #[Test]
    public function renderShowsStackTrace(): void
    {
        $exception = new RuntimeException('test error');
        $output = $this->renderer->render($exception);

        $stripped = SyntaxHighlighter::stripAnsi($output);
        self::assertStringContainsString('Stack trace:', $stripped);
        self::assertStringContainsString('#0', $stripped);
    }

    #[Test]
    public function renderShowsChainedExceptions(): void
    {
        $cause = new Exception('Root cause');
        $exception = new RuntimeException('Wrapper error', 0, $cause);
        $output = $this->renderer->render($exception);

        $stripped = SyntaxHighlighter::stripAnsi($output);
        self::assertStringContainsString('Wrapper error', $stripped);
        self::assertStringContainsString('Caused by (1):', $stripped);
        self::assertStringContainsString('Root cause', $stripped);
    }

    #[Test]
    public function renderTripleChainedExceptions(): void
    {
        $root = new Exception('level 0');
        $middle = new RuntimeException('level 1', 0, $root);
        $top = new RuntimeException('level 2', 0, $middle);

        $output = $this->renderer->render($top);

        $stripped = SyntaxHighlighter::stripAnsi($output);
        self::assertStringContainsString('level 2', $stripped);
        self::assertStringContainsString('Caused by (1):', $stripped);
        self::assertStringContainsString('level 1', $stripped);
        self::assertStringContainsString('Caused by (2):', $stripped);
        self::assertStringContainsString('level 0', $stripped);
    }

    #[Test]
    public function renderWithColorsDisabled(): void
    {
        $plainRenderer = new ErrorRenderer(colorsEnabled: false);
        $exception = new RuntimeException('plain error');

        $output = $plainRenderer->render($exception);

        // No ANSI escape sequences
        self::assertStringNotContainsString("\033[", $output);
        self::assertStringContainsString('RuntimeException', $output);
        self::assertStringContainsString('plain error', $output);
    }

    #[Test]
    public function renderSourceContextForCurrentFile(): void
    {
        $output = $this->renderer->renderSourceContext(__FILE__, __LINE__);

        $stripped = SyntaxHighlighter::stripAnsi($output);
        // Should show the file basename and line number
        self::assertStringContainsString('ErrorRendererTest.php', $stripped);
    }

    #[Test]
    public function renderSourceContextForNonexistentFile(): void
    {
        $output = $this->renderer->renderSourceContext('/nonexistent/file.php', 1);

        self::assertSame('', $output);
    }

    #[Test]
    public function renderSourceContextShowsSurroundingLines(): void
    {
        // Create a temporary file with known content
        $tmpFile = sys_get_temp_dir() . '/pulsar_err_test_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($tmpFile, "<?php\nline2\nline3\nline4\nline5\nline6\nline7\n");

        try {
            $output = $this->renderer->renderSourceContext($tmpFile, 4);

            $stripped = SyntaxHighlighter::stripAnsi($output);
            // Should show lines around line 4
            self::assertStringContainsString('line2', $stripped);
            self::assertStringContainsString('line3', $stripped);
            self::assertStringContainsString('line4', $stripped);
            self::assertStringContainsString('line5', $stripped);
        } finally {
            // HistoryManager-style cleanup via overwrite
            file_put_contents($tmpFile, '');
        }
    }

    #[Test]
    public function renderSourceContextHighlightsErrorLine(): void
    {
        $output = $this->renderer->renderSourceContext(__FILE__, __LINE__);

        // The error line should have the red background marker '>'
        self::assertStringContainsString('>', $output);
    }

    #[Test]
    public function customContextLinesCount(): void
    {
        $renderer = new ErrorRenderer(colorsEnabled: false, contextLines: 1);

        $tmpFile = sys_get_temp_dir() . '/pulsar_ctx_test_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($tmpFile, "L1\nL2\nL3\nL4\nL5\nL6\nL7\nL8\nL9\nL10\n");

        try {
            $output = $renderer->renderSourceContext($tmpFile, 5);

            // With contextLines=1, should show lines 4-6 (1 before, target, 1 after)
            self::assertStringContainsString('L4', $output);
            self::assertStringContainsString('L5', $output);
            self::assertStringContainsString('L6', $output);
            // Should NOT show L2 or L8
            self::assertStringNotContainsString('L2', $output);
            self::assertStringNotContainsString('L8', $output);
        } finally {
            file_put_contents($tmpFile, '');
        }
    }

    #[Test]
    public function renderTruncatesLongStackTraces(): void
    {
        // Create exception with a real trace
        $exception = new RuntimeException('long trace');
        $output = $this->renderer->render($exception);

        // Should not be excessively long
        $lineCount = substr_count($output, "\n");
        self::assertLessThan(100, $lineCount);
    }

    #[Test]
    public function renderIncludesAnsiCodesWhenEnabled(): void
    {
        $exception = new RuntimeException('colored error');
        $output = $this->renderer->render($exception);

        self::assertStringContainsString("\033[", $output);
    }
}
