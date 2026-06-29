<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Core\Boot\ExtensionViewPathRegistrar;
use Pulsar\ErrorHandling\ErrorPageRenderer;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;
use Pulsar\Http\Message\Response;
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
 * Regression tests: composers/shares registered at boot must SURVIVE the
 * engine rebuild that {@see ExtensionViewPathRegistrar} performs whenever a
 * project has a `resources/views/theme/` directory (or extension views).
 *
 * The registrar used to rebuild the engine without the container's
 * {@see ViewComposers} store, so the new engine fell back to an empty one and
 * silently dropped every boot-time registration — breaking the feature's own
 * acceptance criterion that composers apply to framework-internal renders.
 *
 * Everything here is real: real container, real compiler with directives, a
 * real theme/ directory triggering the real rebuild path, and a real
 * ErrorPageRenderer rendering through the rebuilt engine.
 */
#[CoversClass(ExtensionViewPathRegistrar::class)]
final class ExtensionViewPathRegistrarComposerTest extends TestCase
{
    private string $templateDir;

    private string $cacheDir;

    private Container $container;

    private TemplateEngine $originalEngine;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_registrar_composer_' . uniqid();
        $this->templateDir = $base . DIRECTORY_SEPARATOR . 'views';
        $this->cacheDir = $base . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->templateDir, 0o755, true);
        mkdir($this->cacheDir, 0o755, true);
        // A theme/ subdirectory is what triggers the registrar's rebuild path.
        mkdir($this->templateDir . DIRECTORY_SEPARATOR . 'theme', 0o755, true);

        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
        );

        // Assemble the view layer exactly as ViewWiring does.
        $compiler = new TemplateCompiler($config, new TemplateCache($this->cacheDir));
        $directives = new DirectiveRegistry($config);
        $directives->registerBuiltins();
        $directives->bindTo($compiler);
        $composers = new ViewComposers();
        $this->originalEngine = new TemplateEngine($compiler, $composers);

        $this->container = new Container();
        $this->container->instance(ViewConfig::class, $config);
        $this->container->instance(TemplateCache::class, new TemplateCache($this->cacheDir));
        $this->container->instance(TemplateCompiler::class, $compiler);
        $this->container->instance(DirectiveRegistry::class, $directives);
        $this->container->instance(ViewComposers::class, $composers);
        $this->container->instance(TemplateEngineInterface::class, $this->originalEngine);
        $this->container->instance(TemplateEngine::class, $this->originalEngine);
    }

    protected function tearDown(): void
    {
        // The registrar installs the rebuilt engine into the static
        // Response::$templateEngine slot; clear it so it cannot leak.
        Response::clearTemplateEngine();

        $base = dirname($this->templateDir);
        $safe = SafePath::resolveUnder(basename($base), sys_get_temp_dir());
        if ($safe !== null) {
            new SafeFilesystem()->removeDirectoryRecursive($safe);
        }
    }

    #[Test]
    public function bootTimeComposerSurvivesTheThemeTriggeredEngineRebuild(): void
    {
        // Arrange — a '*' composer and a share registered on the ORIGINAL
        // engine (as an app does at boot / route-load time), and a themed
        // errors/404 that needs both values
        $this->writeTemplate('errors.404', '<nav>{{ $banner }}</nav><h1>{{ $siteName }}</h1>');
        $this->originalEngine->composer('*', static fn(): array => ['banner' => 'GLOBAL-CHROME']);
        $this->originalEngine->share('siteName', 'Obez Worlds');

        // Act — the theme/ directory triggers the registrar's engine rebuild;
        // an error page then renders through whatever engine the container holds
        ExtensionViewPathRegistrar::register($this->container, null);

        /** @var TemplateEngineInterface $rebuiltEngine */
        $rebuiltEngine = $this->container->get(TemplateEngineInterface::class);

        $renderer = new ErrorPageRenderer(
            debug: false,
            templateEngineResolver: static fn(): TemplateEngineInterface => $rebuiltEngine,
        );
        $html = $renderer->render(
            new RuntimeException('no route matched'),
            new ServerRequest(method: 'GET', uri: '/missing'),
            ResponseStatus::NotFound,
        );

        // Assert — the rebuild really happened (new engine instance), and the
        // boot-time composer + share both fired through the REBUILT engine
        self::assertNotSame($this->originalEngine, $rebuiltEngine, 'precondition: the registrar must rebuild the engine');
        self::assertStringContainsString('<nav>GLOBAL-CHROME</nav>', $html);
        self::assertStringContainsString('<h1>Obez Worlds</h1>', $html);
        self::assertStringNotContainsString('e__code', $html, 'inline fallback must not be used');
    }

    #[Test]
    public function rebuiltEngineResolvesThemeTemplatesAndComposesThem(): void
    {
        // Arrange — a template that only exists under theme/, plus a composer
        // registered before the rebuild
        $this->writeTemplate('theme.landing', '<section>{{ $banner }}</section>');
        $this->originalEngine->composer('*', static fn(): array => ['banner' => 'THEMED']);

        // Act
        ExtensionViewPathRegistrar::register($this->container, null);

        /** @var TemplateEngineInterface $rebuiltEngine */
        $rebuiltEngine = $this->container->get(TemplateEngineInterface::class);
        $html = $rebuiltEngine->render('theme.landing');

        // Assert — the rebuilt engine gained the theme path AND kept the composer
        self::assertStringContainsString('<section>THEMED</section>', $html);
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
