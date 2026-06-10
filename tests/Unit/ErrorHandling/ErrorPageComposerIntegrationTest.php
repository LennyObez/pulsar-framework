<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\ErrorPageRenderer;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\View\Directive\DirectiveRegistry;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\Engine\TemplateEngine;
use Pulsar\View\Engine\TemplateEngineInterface;
use Pulsar\View\Engine\ViewComposers;
use Pulsar\View\ViewConfig;
use RuntimeException;

use function basename;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * Integration proof for the error-page acceptance criterion: a framework-
 * internal render (ErrorPageRenderer, production mode) goes through the REAL
 * engine and therefore receives view-composer data — so the themed 404 renders
 * the full site header/footer chrome with zero controller involvement.
 *
 * Nothing on the render path is stubbed: real compiler, real templates with
 * real `@include` partials, real ErrorPageRenderer resolving the engine through
 * the same lazy-resolver mechanism the production wiring uses.
 */
#[CoversClass(ErrorPageRenderer::class)]
final class ErrorPageComposerIntegrationTest extends TestCase
{
    private string $templateDir;

    private string $cacheDir;

    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_errpage_composer_' . uniqid();
        $this->templateDir = $base . DIRECTORY_SEPARATOR . 'views';
        $this->cacheDir = $base . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->templateDir, 0o755, true);
        mkdir($this->cacheDir, 0o755, true);

        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
        );

        // Full production compiler configuration: built-in directives bound,
        // exactly as ViewWiring assembles the engine (so @include is live).
        $compiler = new TemplateCompiler($config, new TemplateCache($this->cacheDir));
        $directives = new DirectiveRegistry($config);
        $directives->registerBuiltins();
        $directives->bindTo($compiler);

        $this->engine = new TemplateEngine($compiler, new ViewComposers());
    }

    protected function tearDown(): void
    {
        // Dogfood the framework's own boundary-checked recursive removal.
        $base = dirname($this->templateDir);
        $safe = SafePath::resolveUnder(basename($base), sys_get_temp_dir());
        if ($safe !== null) {
            new SafeFilesystem()->removeDirectoryRecursive($safe);
        }
    }

    #[Test]
    public function themedNotFoundPageRendersRealHeaderAndFooterSuppliedByAComposer(): void
    {
        // Arrange — a themed errors/404 whose chrome comes from real partials,
        // a partial-scoped composer building the localized nav (counted), and a
        // production-mode renderer resolving the engine lazily (as wired).
        $this->writeTemplate(
            'errors.404',
            "@include('theme.partials.header')<main>{{ \$status }} {{ \$statusPhrase }}</main>@include('theme.partials.footer')",
        );
        $this->writeTemplate('theme.partials.header', '<header>{{ $nav }}</header>');
        $this->writeTemplate('theme.partials.footer', '<footer>{{ $nav }}</footer>');

        $navBuilds = 0;
        $this->engine->composer('theme.partials.*', static function () use (&$navBuilds): array {
            ++$navBuilds;

            return ['nav' => 'WORLDS-MEGAMENU'];
        });

        $renderer = new ErrorPageRenderer(
            debug: false,
            templateEngineResolver: fn(): TemplateEngineInterface => $this->engine,
        );

        // Act — an unmatched-route 404 rendered by the framework itself
        $html = $renderer->render(
            new RuntimeException('router: no route matched'),
            new ServerRequest(method: 'GET', uri: '/missing'),
            ResponseStatus::NotFound,
        );

        // Assert — the real chrome rendered through the composers (once), the
        // template path was taken (no inline fallback), and production mode
        // leaked nothing from the exception
        self::assertStringContainsString('<header>WORLDS-MEGAMENU</header>', $html);
        self::assertStringContainsString('<footer>WORLDS-MEGAMENU</footer>', $html);
        self::assertStringContainsString('<main>404 Not Found</main>', $html);
        self::assertSame(1, $navBuilds, 'nav built once although header and footer both match');
        self::assertStringNotContainsString('e__code', $html, 'inline fallback must not be used');
        self::assertStringNotContainsString('no route matched', $html, 'production mode must not leak exception details');
    }

    #[Test]
    public function sharedDataReachesTheErrorTemplateWithoutAnyController(): void
    {
        // Arrange — a global share() supplying the site name, 5xx category page
        $this->writeTemplate('errors.5xx', '<h1>{{ $siteName }}</h1><p>{{ $statusPhrase }}</p>');
        $this->engine->share('siteName', 'Obez Worlds');

        $renderer = new ErrorPageRenderer(
            debug: false,
            templateEngineResolver: fn(): TemplateEngineInterface => $this->engine,
        );

        // Act
        $html = $renderer->render(
            new RuntimeException('boom'),
            new ServerRequest(method: 'GET', uri: '/oops'),
            ResponseStatus::InternalServerError,
        );

        // Assert — shared data flowed into a framework-internal render
        self::assertStringContainsString('<h1>Obez Worlds</h1>', $html);
        self::assertStringContainsString('Internal Server Error', $html);
        self::assertStringNotContainsString('boom', $html);
    }

    /**
     * Write a template file for the given dot-notation name.
     */
    private function writeTemplate(string $name, string $content): void
    {
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $name) . '.pulse.php';
        $path = $this->templateDir . DIRECTORY_SEPARATOR . $relative;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($path, $content);
    }
}
