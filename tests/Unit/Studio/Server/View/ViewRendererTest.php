<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\View;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Server\View\ViewRenderer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(ViewRenderer::class)]
final class ViewRendererTest extends TestCase
{
    private string $templateDir;

    protected function setUp(): void
    {
        $this->templateDir = sys_get_temp_dir() . '/pulsar_view_test_' . uniqid();
        mkdir($this->templateDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupDirectory($this->templateDir);
    }

    #[Test]
    public function renderThrowsExceptionForNonExistentTemplate(): void
    {
        $renderer = new ViewRenderer($this->templateDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Template not found:');

        $renderer->render('nonexistent');
    }

    #[Test]
    public function renderReturnsTemplateContent(): void
    {
        $this->createTemplate('simple', '<?php echo "Hello World"; ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('simple');

        self::assertSame('Hello World', $result);
    }

    #[Test]
    public function renderExtractsVariablesIntoScope(): void
    {
        $this->createTemplate('with_vars', '<?php echo $name . " is " . $age . " years old"; ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('with_vars', ['name' => 'John', 'age' => 30]);

        self::assertSame('John is 30 years old', $result);
    }

    #[Test]
    public function renderHandlesArrayVariables(): void
    {
        $this->createTemplate('with_array', '<?php echo implode(", ", $items); ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('with_array', ['items' => ['apple', 'banana', 'cherry']]);

        self::assertSame('apple, banana, cherry', $result);
    }

    #[Test]
    public function renderHandlesNestedDirectoryTemplate(): void
    {
        mkdir($this->templateDir . '/nested', 0o755, true);
        $this->createTemplate('nested/template', '<?php echo "Nested Template"; ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('nested/template');

        self::assertSame('Nested Template', $result);
    }

    #[Test]
    public function renderHandlesEmptyTemplate(): void
    {
        $this->createTemplate('empty', '');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('empty');

        self::assertSame('', $result);
    }

    #[Test]
    public function renderHandlesHtmlContent(): void
    {
        $this->createTemplate('html', '<div class="test"><p>Hello</p></div>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('html');

        self::assertSame('<div class="test"><p>Hello</p></div>', $result);
    }

    #[Test]
    public function renderHandlesMixedPhpAndHtml(): void
    {
        $this->createTemplate('mixed', '<h1><?php echo $title; ?></h1><p>Static content</p>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('mixed', ['title' => 'Page Title']);

        self::assertSame('<h1>Page Title</h1><p>Static content</p>', $result);
    }

    #[Test]
    public function renderRethrowsExceptionFromTemplate(): void
    {
        $this->createTemplate('throwing', '<?php throw new RuntimeException("Template error"); ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Template error');

        $renderer->render('throwing');
    }

    #[Test]
    public function renderIsolatesScope(): void
    {
        // $this should not be available in the template's static scope
        $this->createTemplate('isolated', '<?php echo isset($this) ? "leaked" : "isolated"; ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('isolated');

        self::assertSame('isolated', $result);
    }

    #[Test]
    public function renderWithLayoutWrapsContentInLayout(): void
    {
        $this->createTemplate('layout', '<!DOCTYPE html><html><head><title><?php echo $title; ?></title></head><body><?php echo $content; ?></body></html>');
        $this->createTemplate('page', '<h1>Page Content</h1>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->renderWithLayout('page', [], 'My Page');

        self::assertStringContainsString('<title>My Page</title>', $result);
        self::assertStringContainsString('<h1>Page Content</h1>', $result);
    }

    #[Test]
    public function renderWithLayoutUsesDefaultTitle(): void
    {
        $this->createTemplate('layout', '<!DOCTYPE html><html><head><title><?php echo $title; ?></title></head><body><?php echo $content; ?></body></html>');
        $this->createTemplate('page', 'Content');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->renderWithLayout('page');

        self::assertStringContainsString('<title>Pulsar Studio</title>', $result);
    }

    #[Test]
    public function renderWithLayoutPassesDataToTemplate(): void
    {
        $this->createTemplate('layout', '<?php echo $content; ?>');
        $this->createTemplate('page', '<?php echo $message; ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->renderWithLayout('page', ['message' => 'Hello World'], 'Title');

        self::assertSame('Hello World', $result);
    }

    #[Test]
    public function renderWithLayoutHandlesNestedTemplate(): void
    {
        mkdir($this->templateDir . '/console', 0o755, true);
        $this->createTemplate('layout', '<main><?php echo $content; ?></main>');
        $this->createTemplate('console/overview', '<section>Console Overview</section>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->renderWithLayout('console/overview');

        self::assertSame('<main><section>Console Overview</section></main>', $result);
    }

    #[Test]
    public function renderHandlesSpecialCharactersInVariables(): void
    {
        $this->createTemplate('special', '<?php echo htmlspecialchars($input, ENT_QUOTES, "UTF-8"); ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('special', ['input' => '<script>alert("xss")</script>']);

        self::assertSame('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $result);
    }

    #[Test]
    public function renderHandlesNullVariables(): void
    {
        $this->createTemplate('nullable', '<?php echo $value ?? "default"; ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('nullable', ['value' => null]);

        self::assertSame('default', $result);
    }

    #[Test]
    public function renderHandlesObjectVariables(): void
    {
        $this->createTemplate('object', '<?php echo $obj->getMessage(); ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $obj = new class {
            public function getMessage(): string
            {
                return 'Object message';
            }
        };

        $result = $renderer->render('object', ['obj' => $obj]);

        self::assertSame('Object message', $result);
    }

    #[Test]
    public function renderHandlesMultilineOutput(): void
    {
        $this->createTemplate('multiline', "Line 1\nLine 2\nLine 3");
        $renderer = new ViewRenderer($this->templateDir);

        $result = $renderer->render('multiline');

        self::assertSame("Line 1\nLine 2\nLine 3", $result);
    }

    #[Test]
    public function renderCleansOutputBufferOnException(): void
    {
        $this->createTemplate('buffer_test', '<?php echo "start"; throw new RuntimeException("error"); echo "end"; ?>');
        $renderer = new ViewRenderer($this->templateDir);

        $beforeLevel = ob_get_level();

        try {
            $renderer->render('buffer_test');
        } catch (RuntimeException) {
            // Expected
        }

        $afterLevel = ob_get_level();
        self::assertSame($beforeLevel, $afterLevel, 'Output buffer should be cleaned on exception');
    }

    private function createTemplate(string $name, string $content): void
    {
        $path = $this->templateDir . '/' . $name . '.php';
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($path, $content);
    }

    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
