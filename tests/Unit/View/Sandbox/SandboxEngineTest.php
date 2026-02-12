<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Sandbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Sandbox\AstInterpreter;
use Pulsar\View\Sandbox\AstNode;
use Pulsar\View\Sandbox\AstNodeType;
use Pulsar\View\Sandbox\AstParser;
use Pulsar\View\Sandbox\SandboxConfig;
use Pulsar\View\Sandbox\SandboxEngine;
use Pulsar\View\Sandbox\TranslationCallback;
use Pulsar\View\ViewException;

#[CoversClass(SandboxEngine::class)]
#[CoversClass(AstParser::class)]
#[CoversClass(AstInterpreter::class)]
#[CoversClass(AstNode::class)]
#[CoversClass(AstNodeType::class)]
#[CoversClass(SandboxConfig::class)]
#[CoversClass(TranslationCallback::class)]
final class SandboxEngineTest extends TestCase
{
    private SandboxConfig $config;

    private SandboxEngine $engine;

    protected function setUp(): void
    {
        $this->config = new SandboxConfig();
        $this->engine = new SandboxEngine($this->config);
    }

    // --- Basic Rendering ---

    #[Test]
    public function rendersStaticText(): void
    {
        self::assertSame('Hello World', $this->engine->render('Hello World'));
    }

    #[Test]
    public function rendersEscapedVariable(): void
    {
        $result = $this->engine->render('Hello, {{ $name }}!', ['name' => 'World']);

        self::assertSame('Hello, World!', $result);
    }

    #[Test]
    public function autoEscapesHtmlInOutput(): void
    {
        $result = $this->engine->render('{{ $input }}', ['input' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;', $result);
    }

    #[Test]
    public function rendersVariableWithDotNotation(): void
    {
        $result = $this->engine->render(
            '{{ $user.name }}',
            ['user' => ['name' => 'Alice']],
        );

        self::assertSame('Alice', $result);
    }

    #[Test]
    public function rendersNullVariableAsEmpty(): void
    {
        $result = $this->engine->render('Value: {{ $missing }}');

        self::assertSame('Value: ', $result);
    }

    // --- @if Directive ---

    #[Test]
    public function ifDirectiveRendersWhenTrue(): void
    {
        $result = $this->engine->render(
            '@if($show)Visible@endif',
            ['show' => true],
        );

        self::assertSame('Visible', $result);
    }

    #[Test]
    public function ifDirectiveSkipsWhenFalse(): void
    {
        $result = $this->engine->render(
            '@if($show)Visible@endif',
            ['show' => false],
        );

        self::assertSame('', $result);
    }

    #[Test]
    public function ifElseDirective(): void
    {
        $result = $this->engine->render(
            '@if($admin)Admin@else User@endif',
            ['admin' => false],
        );

        self::assertSame(' User', $result);
    }

    #[Test]
    public function ifDirectiveWithComparison(): void
    {
        $result = $this->engine->render(
            '@if($count > 0)Has items@endif',
            ['count' => 5],
        );

        self::assertSame('Has items', $result);
    }

    #[Test]
    public function ifDirectiveWithNegation(): void
    {
        $result = $this->engine->render(
            '@if(!$hidden)Shown@endif',
            ['hidden' => false],
        );

        self::assertSame('Shown', $result);
    }

    // --- @foreach Directive ---

    #[Test]
    public function foreachDirectiveIteratesArray(): void
    {
        $result = $this->engine->render(
            '@foreach($items as $item){{ $item }} @endforeach',
            ['items' => ['a', 'b', 'c']],
        );

        self::assertSame('a b c ', $result);
    }

    #[Test]
    public function foreachDirectiveWithKeyValue(): void
    {
        $result = $this->engine->render(
            '@foreach($data as $key => $val){{ $key }}={{ $val }} @endforeach',
            ['data' => ['x' => '1', 'y' => '2']],
        );

        self::assertSame('x=1 y=2 ', $result);
    }

    #[Test]
    public function foreachDirectiveWithEmptyArray(): void
    {
        $result = $this->engine->render(
            '@foreach($items as $item){{ $item }}@endforeach',
            ['items' => []],
        );

        self::assertSame('', $result);
    }

    #[Test]
    public function foreachDirectiveWithNonIterable(): void
    {
        $result = $this->engine->render(
            '@foreach($items as $item){{ $item }}@endforeach',
            ['items' => 'not-iterable'],
        );

        self::assertSame('', $result);
    }

    // --- @include Directive ---

    #[Test]
    public function includeRendersAllowlistedTemplate(): void
    {
        $config = new SandboxConfig(
            includeAllowlist: ['header' => '<header>{{ $title }}</header>'],
        );
        $engine = new SandboxEngine($config);

        $result = $engine->render(
            "@include('header')",
            ['title' => 'My Page'],
        );

        self::assertSame('<header>My Page</header>', $result);
    }

    #[Test]
    public function includeThrowsForNonAllowlistedTemplate(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/allowlist/');

        $this->engine->render("@include('evil-template')");
    }

    // --- @i18n Directive ---

    #[Test]
    public function i18nDirectiveUsesTranslationCallback(): void
    {
        $callback = new TranslationCallback(fn(string $key, array $data): string => "Translated: {$key}");

        $engine = new SandboxEngine($this->config, $callback);

        $result = $engine->render("@i18n('welcome.message')");

        self::assertSame('Translated: welcome.message', $result);
    }

    #[Test]
    public function i18nDirectiveReturnsKeyWithoutCallback(): void
    {
        $result = $this->engine->render("@i18n('hello.world')");

        self::assertSame('hello.world', $result);
    }

    #[Test]
    public function i18nDirectiveEscapesOutput(): void
    {
        $callback = new TranslationCallback(fn(string $key, array $data): string => '<b>Bold</b>');
        $engine = new SandboxEngine($this->config, $callback);

        $result = $engine->render("@i18n('test')");

        self::assertStringNotContainsString('<b>', $result);
        self::assertStringContainsString('&lt;b&gt;', $result);
    }

    // --- Security: Disallowed Constructs ---

    #[Test]
    public function rejectsRawOutput(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/Raw unescaped/');

        $this->engine->render('{!! $html !!}', ['html' => '<b>test</b>']);
    }

    #[Test]
    public function rejectsPhpDirective(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/@php/i');

        $this->engine->render('@php echo "hello"; @endphp');
    }

    #[Test]
    public function rejectsPhpTags(): void
    {
        $this->expectException(ViewException::class);

        $this->engine->render('<?php echo "hello"; ?>');
    }

    #[Test]
    public function rejectsShortEchoTags(): void
    {
        $this->expectException(ViewException::class);

        $this->engine->render('<?= "hello" ?>');
    }

    #[Test]
    public function rejectsDisallowedDirectives(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/not allowed/');

        $this->engine->render('@extends(\'layouts.main\')');
    }

    // --- Resource Limits ---

    #[Test]
    public function enforcesStepLimit(): void
    {
        $config = new SandboxConfig(stepLimit: 5);
        $engine = new SandboxEngine($config);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/step limit/');

        // Each node is one step; many nodes will exceed the limit of 5
        $engine->render(
            '@foreach($items as $item){{ $item }}@endforeach',
            ['items' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']],
        );
    }

    #[Test]
    public function enforcesLoopLimit(): void
    {
        $config = new SandboxConfig(loopLimit: 3);
        $engine = new SandboxEngine($config);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/loop iteration limit/');

        $engine->render(
            '@foreach($items as $item){{ $item }}@endforeach',
            ['items' => range(1, 100)],
        );
    }

    #[Test]
    public function enforcesOutputSizeLimit(): void
    {
        $config = new SandboxConfig(outputSizeLimit: 50);
        $engine = new SandboxEngine($config);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/output size limit/');

        $engine->render(
            '@foreach($items as $item)This is a long string of text. @endforeach',
            ['items' => range(1, 100)],
        );
    }

    // --- SandboxConfig Tests ---

    #[Test]
    public function sandboxConfigFromViewConfig(): void
    {
        $viewConfig = new \Pulsar\View\ViewConfig(
            templatePaths: ['/views'],
            cachePath: '/cache',
            sandboxStepLimit: 5_000,
            sandboxLoopLimit: 500,
            sandboxOutputSizeLimit: 512_000,
            sandboxWallClockCheckInterval: 250,
        );

        $config = SandboxConfig::fromViewConfig($viewConfig);

        self::assertSame(5_000, $config->stepLimit);
        self::assertSame(500, $config->loopLimit);
        self::assertSame(512_000, $config->outputSizeLimit);
        self::assertSame(250, $config->wallClockCheckInterval);
    }

    #[Test]
    public function sandboxConfigIncludeAllowlist(): void
    {
        $config = new SandboxConfig(
            includeAllowlist: ['header' => '<h1>Header</h1>'],
        );

        self::assertTrue($config->isIncludeAllowed('header'));
        self::assertFalse($config->isIncludeAllowed('footer'));
        self::assertSame('<h1>Header</h1>', $config->getIncludeContent('header'));
        self::assertNull($config->getIncludeContent('footer'));
    }

    // --- Combined Templates ---

    #[Test]
    public function rendersComplexTemplate(): void
    {
        $config = new SandboxConfig(
            includeAllowlist: ['badge' => '<span class="badge">{{ $label }}</span>'],
        );
        $engine = new SandboxEngine($config);

        $template = <<<'TPL'
            <h1>{{ $title }}</h1>
            @if($items)
            <ul>
            @foreach($items as $item)
            <li>{{ $item.name }} @include('badge')</li>
            @endforeach
            </ul>
            @else
            <p>No items</p>
            @endif
            TPL;

        $result = $engine->render($template, [
            'title' => 'Products',
            'items' => [
                ['name' => 'Widget A'],
                ['name' => 'Widget B'],
            ],
            'label' => 'new',
        ]);

        self::assertStringContainsString('Products', $result);
        self::assertStringContainsString('Widget A', $result);
        self::assertStringContainsString('Widget B', $result);
        self::assertStringContainsString('badge', $result);
    }

    #[Test]
    public function unclosedDirectiveBlockThrows(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/unclosed/');

        $this->engine->render('@if($x)content without endif');
    }

    #[Test]
    public function xssPayloadIsEscapedInAllContexts(): void
    {
        $xssPayloads = [
            '<script>alert(1)</script>',
            '" onmouseover="alert(1)',
            "' onfocus='alert(1)'",
            '<img src=x onerror=alert(1)>',
            '<svg/onload=alert(1)>',
        ];

        foreach ($xssPayloads as $payload) {
            $result = $this->engine->render('{{ $input }}', ['input' => $payload]);

            // All angle brackets must be entity-encoded
            self::assertStringNotContainsString('<script>', $result, "XSS not escaped: {$payload}");
            self::assertStringNotContainsString('<img', $result, "XSS not escaped: {$payload}");
            self::assertStringNotContainsString('<svg', $result, "XSS not escaped: {$payload}");
            // Quotes must be entity-encoded so they can't break out of attribute context
            self::assertStringNotContainsString('"', $result, "XSS not escaped: {$payload}");
        }
    }
}
