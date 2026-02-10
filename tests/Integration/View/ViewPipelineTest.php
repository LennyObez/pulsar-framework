<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\DirectiveRegistry;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\Engine\TemplateEngine;
use Pulsar\View\Engine\TemplateInheritance;
use Pulsar\View\Escaping\AttributeEscaper;
use Pulsar\View\Escaping\CssEscaper;
use Pulsar\View\Escaping\HtmlEscaper;
use Pulsar\View\Escaping\JsEscaper;
use Pulsar\View\Escaping\UrlEscaper;
use Pulsar\View\Sandbox\SandboxConfig;
use Pulsar\View\Sandbox\SandboxEngine;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function scandir;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Integration tests for the full View module pipeline.
 */
#[CoversClass(TemplateEngine::class)]
#[CoversClass(TemplateCompiler::class)]
#[CoversClass(TemplateCache::class)]
#[CoversClass(DirectiveRegistry::class)]
#[CoversClass(TemplateInheritance::class)]
#[CoversClass(SandboxEngine::class)]
final class ViewPipelineTest extends TestCase
{
    private string $templateDir;

    private string $cacheDir;

    private TemplateEngine $engine;

    private ViewConfig $config;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_integration_view_' . uniqid();
        $this->templateDir = $base . DIRECTORY_SEPARATOR . 'views';
        $this->cacheDir = $base . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->templateDir, 0o755, true);
        mkdir($this->cacheDir, 0o755, true);

        $this->config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
        );

        $cache = new TemplateCache($this->cacheDir);
        $compiler = new TemplateCompiler($this->config, $cache);

        // Register built-in directives
        $registry = new DirectiveRegistry($this->config);
        $registry->registerBuiltins();
        $registry->bindTo($compiler);

        $this->engine = new TemplateEngine($compiler);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive(dirname($this->templateDir));
    }

    // --- Full Rendering Pipeline ---

    #[Test]
    public function rendersSimpleTemplateWithEscaping(): void
    {
        $this->writeTemplate('welcome', '<h1>{{ $title }}</h1><p>{{ $body }}</p>');

        $result = $this->engine->render('welcome', [
            'title' => 'Hello & Welcome',
            'body' => 'Content with <em>tags</em>',
        ]);

        self::assertStringContainsString('Hello &amp; Welcome', $result);
        self::assertStringContainsString('&lt;em&gt;tags&lt;/em&gt;', $result);
    }

    #[Test]
    public function rendersTemplateWithIfDirective(): void
    {
        $this->writeTemplate('conditional', '@if($show)<p>Visible</p>@endif');

        $visible = $this->engine->render('conditional', ['show' => true]);
        self::assertStringContainsString('<p>Visible</p>', $visible);

        $hidden = $this->engine->render('conditional', ['show' => false]);
        self::assertStringNotContainsString('<p>Visible</p>', $hidden);
    }

    #[Test]
    public function rendersTemplateWithForeachDirective(): void
    {
        $this->writeTemplate('list', '<ul>@foreach($items as $item)<li>{{ $item }}</li>@endforeach</ul>');

        $result = $this->engine->render('list', ['items' => ['Apple', 'Banana', 'Cherry']]);

        self::assertStringContainsString('<li>Apple</li>', $result);
        self::assertStringContainsString('<li>Banana</li>', $result);
        self::assertStringContainsString('<li>Cherry</li>', $result);
    }

    #[Test]
    public function rendersTemplateWithCsrfDirective(): void
    {
        $this->writeTemplate('form', '<form>@csrf</form>');

        $result = $this->engine->render('form', ['__csrf' => 'test-token-123']);

        self::assertStringContainsString('_token', $result);
        self::assertStringContainsString('test-token-123', $result);
        self::assertStringContainsString('hidden', $result);
    }

    #[Test]
    public function rendersTemplateWithMethodDirective(): void
    {
        $this->writeTemplate('delete-form', '<form>@method(\'DELETE\')</form>');

        $result = $this->engine->render('delete-form');

        self::assertStringContainsString('_method', $result);
        self::assertStringContainsString('DELETE', $result);
    }

    #[Test]
    public function phpDirectiveRejectedWhenDisabled(): void
    {
        $this->writeTemplate('php-block', '@php echo "test"; @endphp');

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/@php/');

        $this->engine->render('php-block');
    }

    // --- Escaper Integration ---

    #[Test]
    public function htmlEscaperPreventsXssInTemplates(): void
    {
        $this->writeTemplate('xss', '{{ $input }}');

        $result = $this->engine->render('xss', [
            'input' => '<script>alert("XSS")</script>',
        ]);

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;', $result);
    }

    #[Test]
    public function rawOutputBypassesEscaping(): void
    {
        $this->writeTemplate('raw', '{!! $html !!}');

        $result = $this->engine->render('raw', [
            'html' => '<strong>Trusted HTML</strong>',
        ]);

        self::assertSame('<strong>Trusted HTML</strong>', $result);
    }

    // --- Sandbox Integration ---

    #[Test]
    public function sandboxRendersUntrustedTemplates(): void
    {
        $sandbox = new SandboxEngine(SandboxConfig::fromViewConfig($this->config));

        $result = $sandbox->render(
            '<p>Hello, {{ $name }}!</p>',
            ['name' => 'World'],
        );

        self::assertSame('<p>Hello, World!</p>', $result);
    }

    #[Test]
    public function sandboxBlocksRawOutput(): void
    {
        $sandbox = new SandboxEngine(SandboxConfig::fromViewConfig($this->config));

        $this->expectException(ViewException::class);

        $sandbox->render('{!! $html !!}', ['html' => '<b>test</b>']);
    }

    #[Test]
    public function sandboxEnforcesLoopLimits(): void
    {
        $sandboxConfig = new SandboxConfig(loopLimit: 5);
        $sandbox = new SandboxEngine($sandboxConfig);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/loop/');

        $sandbox->render(
            '@foreach($items as $item){{ $item }}@endforeach',
            ['items' => range(1, 100)],
        );
    }

    // --- Context-Aware Escaping ---

    #[Test]
    public function escapersWorkCorrectlyInDifferentContexts(): void
    {
        $html = new HtmlEscaper();
        $url = new UrlEscaper();
        $attr = new AttributeEscaper();
        $js = new JsEscaper();
        $css = new CssEscaper();

        $malicious = '<script>alert(1)</script>';

        // HTML context
        self::assertStringNotContainsString('<script>', $html->escape($malicious));

        // URL context
        self::assertSame('', $url->escape('javascript:alert(1)'));

        // Attribute context
        $attrResult = $attr->escape('" onclick="alert(1)');
        self::assertStringNotContainsString('"', $attrResult);

        // JS context
        $jsResult = $js->escape('</script><script>alert(1)</script>');
        self::assertStringNotContainsString('</script>', $jsResult);

        // CSS context
        $cssResult = $css->escape('expression(alert(1))');
        self::assertStringNotContainsString('(', $cssResult);
    }

    // --- Cache Integration ---

    #[Test]
    public function compiledTemplateIsCachedAndReused(): void
    {
        $this->writeTemplate('cached', '<p>{{ $text }}</p>');

        $first = $this->engine->compile('cached');
        $second = $this->engine->compile('cached');

        self::assertSame($first->compiledPath, $second->compiledPath);
        self::assertSame($first->sourceHash, $second->sourceHash);
    }

    #[Test]
    public function cacheInvalidatedOnSourceChange(): void
    {
        $this->writeTemplate('mutable', '<p>V1</p>');
        $first = $this->engine->compile('mutable');

        $this->writeTemplate('mutable', '<p>V2</p>');
        $second = $this->engine->compile('mutable');

        self::assertNotSame($first->sourceHash, $second->sourceHash);
    }

    private function writeTemplate(string $name, string $content): void
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $name) . '.pulsar.php';
        $fullPath = $this->templateDir . DIRECTORY_SEPARATOR . $relativePath;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($fullPath, $content);
    }

    private function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                $this->removeRecursive($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($path);
    }
}
