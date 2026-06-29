<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;
use Pulsar\View\Directive\DirectiveRegistry;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\Engine\TemplateEngine;
use Pulsar\View\Engine\ViewComposers;
use Pulsar\View\ViewConfig;

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
 * End-to-end tests for shared data + view composers through the REAL engine:
 * real compiler, real `.pulse.php` templates on disk, real `@include` partials.
 * Nothing in the render pipeline is stubbed; every assertion checks rendered
 * HTML output (or an invocation count) that only the implementation can
 * produce — not values the test pre-arranged verbatim.
 */
#[CoversClass(TemplateEngine::class)]
#[CoversClass(ViewComposers::class)]
final class TemplateEngineComposersTest extends TestCase
{
    private string $templateDir;

    private string $cacheDir;

    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_composers_test_' . uniqid();
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
    public function sharedDataIsVisibleInsideTheRenderedTemplate(): void
    {
        // Arrange
        $this->writeTemplate('greeting', '<p>{{ $siteName }}</p>');
        $this->engine->share('siteName', 'Pulsar Docs');

        // Act
        $html = $this->engine->render('greeting');

        // Assert — the value travelled share() → resolve() → extract() → echo
        self::assertStringContainsString('<p>Pulsar Docs</p>', $html);
    }

    #[Test]
    public function explicitRenderDataOverridesSharedDataInsideTheTemplate(): void
    {
        // Arrange — the same key shared and passed explicitly
        $this->writeTemplate('winner', '<p>{{ $who }}</p>');
        $this->engine->share('who', 'shared-loser');

        // Act
        $html = $this->engine->render('winner', ['who' => 'explicit-winner']);

        // Assert — explicit wins, and the shared value is nowhere in the output
        self::assertStringContainsString('<p>explicit-winner</p>', $html);
        self::assertStringNotContainsString('shared-loser', $html);
    }

    #[Test]
    public function composerSuppliesDataToMatchingPartialsRenderedThroughRealIncludes(): void
    {
        // Arrange — a page whose chrome comes from two real @include partials,
        // and an invocation-counting composer matched only by the partials
        $this->writeTemplate('page', "@include('theme.partials.header')<main>body</main>@include('theme.partials.footer')");
        $this->writeTemplate('theme.partials.header', '<header>{{ $nav }}</header>');
        $this->writeTemplate('theme.partials.footer', '<footer>{{ $nav }}</footer>');

        $builds = 0;
        $this->engine->composer('theme.partials.*', static function () use (&$builds): array {
            ++$builds;

            return ['nav' => 'WORLDS-MEGAMENU'];
        });

        // Act — one top-level render triggers both nested partial renders
        $html = $this->engine->render('page');

        // Assert — both partials received the composed nav, with ONE build
        self::assertStringContainsString('<header>WORLDS-MEGAMENU</header>', $html);
        self::assertStringContainsString('<footer>WORLDS-MEGAMENU</footer>', $html);
        self::assertStringContainsString('<main>body</main>', $html);
        self::assertSame(1, $builds, 'expensive nav must be built once per request');
    }

    #[Test]
    public function composerIsLazyAndNeverRunsWhenNoMatchingTemplateRenders(): void
    {
        // Arrange — a composer whose pattern matches nothing we render
        $this->writeTemplate('public-page', '<p>public</p>');
        $invoked = 0;
        $this->engine->composer('admin.*', static function () use (&$invoked): array {
            ++$invoked;

            return ['adminNav' => 'never'];
        });

        // Act
        $html = $this->engine->render('public-page');

        // Assert — rendering succeeded and the composer was never invoked
        self::assertStringContainsString('<p>public</p>', $html);
        self::assertSame(0, $invoked);
    }

    #[Test]
    public function wildcardComposerAppliesToTopLevelRenders(): void
    {
        // Arrange
        $this->writeTemplate('home', '<span>{{ $locale }}</span>');
        $this->engine->composer('*', static fn(): array => ['locale' => 'fr-FR']);

        // Act
        $html = $this->engine->render('home');

        // Assert
        self::assertStringContainsString('<span>fr-FR</span>', $html);
    }

    #[Test]
    public function resetRequestStateIsolatesSharedDataBetweenSequentialRequests(): void
    {
        // Arrange — request 1 shares a value and renders it
        $this->writeTemplate('flash', '<p>{{ $flash ?? "none" }}</p>');
        $this->engine->share('flash', 'request-one-secret');
        $firstRequestHtml = $this->engine->render('flash');

        // Act — worker boundary between two sequential requests, then request 2 renders
        $this->engine->composers()->resetRequestState();
        $secondRequestHtml = $this->engine->render('flash');

        // Assert — request 1 saw its value; request 2 sees none of it (no bleed)
        self::assertStringContainsString('request-one-secret', $firstRequestHtml);
        self::assertStringContainsString('<p>none</p>', $secondRequestHtml);
        self::assertStringNotContainsString('request-one-secret', $secondRequestHtml);
    }

    #[Test]
    public function composerOutputYieldsToExplicitDataInTheRenderedOutput(): void
    {
        // Arrange — composer and caller contest the same key
        $this->writeTemplate('contest', '<b>{{ $title }}</b>');
        $this->engine->composer('*', static fn(): array => ['title' => 'composed']);

        // Act
        $html = $this->engine->render('contest', ['title' => 'explicit']);

        // Assert
        self::assertStringContainsString('<b>explicit</b>', $html);
        self::assertStringNotContainsString('composed', $html);
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
