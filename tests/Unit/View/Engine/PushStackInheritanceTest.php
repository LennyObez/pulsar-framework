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
use Pulsar\View\Engine\TemplateInheritance;
use Pulsar\View\Engine\ViewComposers;
use Pulsar\View\ViewConfig;

use function basename;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function str_replace;
use function strpos;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * End-to-end tests for @push/@stack through the REAL engine, including across
 * the @extends boundary — the case the directive-only unit tests never covered.
 * Every assertion checks rendered HTML the implementation must produce.
 */
#[CoversClass(TemplateEngine::class)]
#[CoversClass(TemplateInheritance::class)]
final class PushStackInheritanceTest extends TestCase
{
    private string $templateDir;
    private string $cacheDir;
    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_pushstack_test_' . uniqid();
        $this->templateDir = $base . DIRECTORY_SEPARATOR . 'views';
        $this->cacheDir = $base . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->templateDir, 0o755, true);
        mkdir($this->cacheDir, 0o755, true);

        $config = new ViewConfig(templatePaths: [$this->templateDir], cachePath: $this->cacheDir);
        $compiler = new TemplateCompiler($config, new TemplateCache($this->cacheDir));
        $directives = new DirectiveRegistry($config);
        $directives->registerBuiltins();
        $directives->bindTo($compiler);

        $this->engine = new TemplateEngine($compiler, new ViewComposers());
    }

    protected function tearDown(): void
    {
        $base = dirname($this->templateDir);
        $safe = SafePath::resolveUnder(basename($base), sys_get_temp_dir());
        if ($safe !== null) {
            new SafeFilesystem()->removeDirectoryRecursive($safe);
        }
    }

    #[Test]
    public function multiplePushBlocksInOneTemplateAllRenderInOrder(): void
    {
        $this->writeTemplate('single', <<<'PULSE'
            @push('scripts')<script src="home.js"></script>@endpush
            @push('scripts')<script src="marquee.js"></script>@endpush
            <div>@stack('scripts')</div>
            PULSE);

        $html = $this->engine->render('single');

        self::assertStringContainsString('home.js', $html);
        self::assertStringContainsString('marquee.js', $html);
        self::assertLessThan(strpos($html, 'marquee.js'), strpos($html, 'home.js'));
    }

    #[Test]
    public function oneBlockWithTwoLinesEmitsBoth(): void
    {
        $this->writeTemplate('twolines', <<<'PULSE'
            @push('scripts')
            <script src="x.js"></script>
            <script src="y.js"></script>
            @endpush
            <div>@stack('scripts')</div>
            PULSE);

        $html = $this->engine->render('twolines');

        self::assertStringContainsString('x.js', $html);
        self::assertStringContainsString('y.js', $html);
    }

    #[Test]
    public function pushesInChildRenderViaLayoutStackAcrossExtends(): void
    {
        $this->writeTemplate('layouts.app', <<<'PULSE'
            <html><head>@stack('scripts')</head><body>@yield('content')</body></html>
            PULSE);

        $this->writeTemplate('home', <<<'PULSE'
            @extends('layouts.app')
            @section('content')<main>home</main>@endsection
            @push('scripts')<script src="home.js"></script>@endpush
            @push('scripts')<script src="marquee.js"></script>@endpush
            PULSE);

        $html = $this->engine->render('home');

        self::assertStringContainsString('<main>home</main>', $html);
        self::assertStringContainsString('home.js', $html);
        self::assertStringContainsString('marquee.js', $html);
        self::assertLessThan(strpos($html, 'marquee.js'), strpos($html, 'home.js'));
        // The stack rendered inside the layout's <head>, before the body.
        self::assertLessThan(strpos($html, '<main>home</main>'), strpos($html, 'home.js'));
    }

    #[Test]
    public function stackRenderedBeforePushIsStillEmitted(): void
    {
        // The layout's @stack sits in <head>, above @yield where the child pushes —
        // i.e. the push happens after the stack in render order. It must still emit.
        $this->writeTemplate('layouts.head', <<<'PULSE'
            <head>@stack('scripts')</head><body>@yield('content')</body>
            PULSE);

        $this->writeTemplate('late', <<<'PULSE'
            @extends('layouts.head')
            @section('content')<main>x</main>@endsection
            @push('scripts')<script src="late.js"></script>@endpush
            PULSE);

        $html = $this->engine->render('late');

        self::assertStringContainsString('late.js', $html);
        self::assertLessThan(strpos($html, '<main>x</main>'), strpos($html, 'late.js'));
    }

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
