<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\AuthDirective;
use Pulsar\View\Directive\CanDirective;
use Pulsar\View\Directive\CaseDirective;
use Pulsar\View\Directive\ComponentDirective;
use Pulsar\View\Directive\ControlFlowDirective;
use Pulsar\View\Directive\CsrfDirective;
use Pulsar\View\Directive\DirectiveRegistry;
use Pulsar\View\Directive\ExtendsDirective;
use Pulsar\View\Directive\GuestDirective;
use Pulsar\View\Directive\I18nDirective;
use Pulsar\View\Directive\IncludeDirective;
use Pulsar\View\Directive\MethodDirective;
use Pulsar\View\Directive\PhpDirective;
use Pulsar\View\Directive\SectionDirective;
use Pulsar\View\Directive\SimpleDirective;
use Pulsar\View\Directive\SlotDirective;
use Pulsar\View\Directive\YieldDirective;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

#[CoversClass(DirectiveRegistry::class)]
#[CoversClass(SimpleDirective::class)]
#[CoversClass(ControlFlowDirective::class)]
#[CoversClass(CaseDirective::class)]
#[CoversClass(ExtendsDirective::class)]
#[CoversClass(SectionDirective::class)]
#[CoversClass(YieldDirective::class)]
#[CoversClass(IncludeDirective::class)]
#[CoversClass(ComponentDirective::class)]
#[CoversClass(SlotDirective::class)]
#[CoversClass(AuthDirective::class)]
#[CoversClass(GuestDirective::class)]
#[CoversClass(CanDirective::class)]
#[CoversClass(CsrfDirective::class)]
#[CoversClass(MethodDirective::class)]
#[CoversClass(I18nDirective::class)]
#[CoversClass(PhpDirective::class)]
final class DirectiveRegistryTest extends TestCase
{
    private ViewConfig $config;

    private DirectiveRegistry $registry;

    protected function setUp(): void
    {
        $this->config = new ViewConfig(
            templatePaths: ['/views'],
            cachePath: '/cache',
        );

        $this->registry = new DirectiveRegistry($this->config);
    }

    #[Test]
    public function registerAndRetrieveDirective(): void
    {
        $directive = new SimpleDirective('test', '<?php /* test */ ?>');
        $this->registry->register($directive);

        self::assertTrue($this->registry->has('test'));
        self::assertSame($directive, $this->registry->get('test'));
    }

    #[Test]
    public function hasReturnsFalseForUnregistered(): void
    {
        self::assertFalse($this->registry->has('nonexistent'));
    }

    #[Test]
    public function getReturnsNullForUnregistered(): void
    {
        self::assertNull($this->registry->get('nonexistent'));
    }

    #[Test]
    public function namesReturnsRegisteredDirectiveNames(): void
    {
        $this->registry->register(new SimpleDirective('alpha', ''));
        $this->registry->register(new SimpleDirective('beta', ''));

        $names = $this->registry->names();

        self::assertContains('alpha', $names);
        self::assertContains('beta', $names);
    }

    #[Test]
    public function registerBuiltinsRegistersAllExpectedDirectives(): void
    {
        $this->registry->registerBuiltins();

        $expected = [
            'if', 'elseif', 'else', 'endif',
            'foreach', 'endforeach',
            'for', 'endfor',
            'while', 'endwhile',
            'switch', 'case', 'default', 'endswitch',
            'extends', 'section', 'endsection', 'yield', 'include',
            'component', 'endcomponent', 'slot', 'endslot',
            'auth', 'endauth', 'guest', 'endguest', 'can', 'endcan',
            'csrf', 'method',
            'i18n',
            'pagination',
            'php', 'endphp',
        ];

        foreach ($expected as $name) {
            self::assertTrue($this->registry->has($name), "Missing directive: @{$name}");
        }
    }

    #[Test]
    public function bindToRegistersDirectivesWithCompiler(): void
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_directive_bind_' . uniqid();
        $compiler = new TemplateCompiler($this->config, new TemplateCache($cacheDir));

        $this->registry->register(new SimpleDirective('test', '<?php /* bound */ ?>'));
        $this->registry->bindTo($compiler);

        self::assertTrue($compiler->hasDirective('test'));
    }

    // --- Control Flow Directive Tests ---

    #[Test]
    public function ifDirectiveCompilesCorrectly(): void
    {
        $directive = new ControlFlowDirective('if', 'if');

        self::assertSame('<?php if ($active): ?>', $directive->compile('$active'));
    }

    #[Test]
    public function elseifDirectiveCompilesCorrectly(): void
    {
        $directive = new ControlFlowDirective('elseif', 'elseif');

        self::assertSame('<?php elseif ($count > 0): ?>', $directive->compile('$count > 0'));
    }

    #[Test]
    public function foreachDirectiveCompilesCorrectly(): void
    {
        $directive = new ControlFlowDirective('foreach', 'foreach');

        self::assertSame(
            '<?php foreach ($items as $item): ?>',
            $directive->compile('$items as $item'),
        );
    }

    #[Test]
    public function forDirectiveCompilesCorrectly(): void
    {
        $directive = new ControlFlowDirective('for', 'for');

        self::assertSame(
            '<?php for ($i = 0; $i < 10; $i++): ?>',
            $directive->compile('$i = 0; $i < 10; $i++'),
        );
    }

    #[Test]
    public function whileDirectiveCompilesCorrectly(): void
    {
        $directive = new ControlFlowDirective('while', 'while');

        self::assertSame('<?php while ($running): ?>', $directive->compile('$running'));
    }

    #[Test]
    public function switchDirectiveCompilesCorrectly(): void
    {
        $directive = new ControlFlowDirective('switch', 'switch');

        self::assertSame('<?php switch ($status): ?>', $directive->compile('$status'));
    }

    #[Test]
    public function caseDirectiveCompilesCorrectly(): void
    {
        $directive = new CaseDirective();

        self::assertSame('case', $directive->name());
        self::assertSame("<?php case 'active': ?>", $directive->compile("'active'"));
    }

    // --- Simple Directive Tests ---

    #[Test]
    public function elseDirectiveCompilesToStaticOutput(): void
    {
        $directive = new SimpleDirective('else', '<?php else: ?>');

        self::assertSame('else', $directive->name());
        self::assertSame('<?php else: ?>', $directive->compile(''));
    }

    #[Test]
    public function endifDirectiveCompilesToStaticOutput(): void
    {
        $directive = new SimpleDirective('endif', '<?php endif; ?>');

        self::assertSame('<?php endif; ?>', $directive->compile('ignored expression'));
    }

    // --- Template Structure Directive Tests ---

    #[Test]
    public function extendsDirectiveCompilesCorrectly(): void
    {
        $directive = new ExtendsDirective();

        self::assertSame('extends', $directive->name());
        self::assertSame(
            "<?php \$__env->setParent('layouts.main'); ?>",
            $directive->compile("'layouts.main'"),
        );
    }

    #[Test]
    public function sectionDirectiveCompilesCorrectly(): void
    {
        $directive = new SectionDirective();

        self::assertSame('section', $directive->name());
        self::assertSame(
            "<?php \$__env->startSection('content'); ?>",
            $directive->compile("'content'"),
        );
    }

    #[Test]
    public function yieldDirectiveCompilesCorrectly(): void
    {
        $directive = new YieldDirective();

        self::assertSame('yield', $directive->name());
        self::assertSame(
            "<?php echo \$__env->yieldSection('content'); ?>",
            $directive->compile("'content'"),
        );
    }

    #[Test]
    public function yieldDirectiveWithDefaultCompilesCorrectly(): void
    {
        $directive = new YieldDirective();

        self::assertSame(
            "<?php echo \$__env->yieldSection('sidebar', 'Default Content'); ?>",
            $directive->compile("'sidebar', 'Default Content'"),
        );
    }

    #[Test]
    public function includeDirectiveCompilesCorrectly(): void
    {
        $directive = new IncludeDirective();

        self::assertSame('include', $directive->name());
        self::assertSame(
            "<?php echo \$__env->renderInclude('partials.header'); ?>",
            $directive->compile("'partials.header'"),
        );
    }

    #[Test]
    public function includeDirectiveWithDataCompilesCorrectly(): void
    {
        $directive = new IncludeDirective();

        self::assertSame(
            "<?php echo \$__env->renderInclude('partials.nav', ['active' => 'home']); ?>",
            $directive->compile("'partials.nav', ['active' => 'home']"),
        );
    }

    // --- Component Directive Tests ---

    #[Test]
    public function componentDirectiveCompilesCorrectly(): void
    {
        $directive = new ComponentDirective();

        self::assertSame('component', $directive->name());
        self::assertSame(
            "<?php \$__env->startComponent('components.alert'); ?>",
            $directive->compile("'components.alert'"),
        );
    }

    #[Test]
    public function componentDirectiveWithDataCompilesCorrectly(): void
    {
        $directive = new ComponentDirective();

        self::assertSame(
            "<?php \$__env->startComponent('components.alert', ['type' => 'danger']); ?>",
            $directive->compile("'components.alert', ['type' => 'danger']"),
        );
    }

    #[Test]
    public function slotDirectiveCompilesCorrectly(): void
    {
        $directive = new SlotDirective();

        self::assertSame('slot', $directive->name());
        self::assertSame(
            "<?php \$__env->startSlot('title'); ?>",
            $directive->compile("'title'"),
        );
    }

    // --- Auth Directive Tests ---

    #[Test]
    public function authDirectiveCompilesWithoutGuard(): void
    {
        $directive = new AuthDirective();

        self::assertSame('auth', $directive->name());
        self::assertSame('<?php if ($__auth->authenticated()): ?>', $directive->compile(''));
    }

    #[Test]
    public function authDirectiveCompilesWithGuard(): void
    {
        $directive = new AuthDirective();

        // The guard argument is accepted but ignored: TemplateAuthHelper has no
        // guard concept, so @auth('api') compiles the same as @auth.
        self::assertSame(
            '<?php if ($__auth->authenticated()): ?>',
            $directive->compile("'api'"),
        );
    }

    #[Test]
    public function guestDirectiveCompilesWithoutGuard(): void
    {
        $directive = new GuestDirective();

        self::assertSame('guest', $directive->name());
        self::assertSame('<?php if ($__auth->guest()): ?>', $directive->compile(''));
    }

    #[Test]
    public function guestDirectiveCompilesWithGuard(): void
    {
        $directive = new GuestDirective();

        // Guard argument accepted but ignored (see authDirectiveCompilesWithGuard).
        self::assertSame(
            '<?php if ($__auth->guest()): ?>',
            $directive->compile("'admin'"),
        );
    }

    #[Test]
    public function canDirectiveCompilesCorrectly(): void
    {
        $directive = new CanDirective();

        self::assertSame('can', $directive->name());
        self::assertSame(
            "<?php if (\$__auth->can('edit', \$post)): ?>",
            $directive->compile("'edit', \$post"),
        );
    }

    // --- Form Directive Tests ---

    #[Test]
    public function csrfDirectiveOutputsHiddenField(): void
    {
        $directive = new CsrfDirective();

        self::assertSame('csrf', $directive->name());

        $output = $directive->compile('');

        self::assertStringContainsString('_token', $output);
        self::assertStringContainsString('hidden', $output);
        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString('$__csrf', $output);
    }

    #[Test]
    public function methodDirectiveOutputsHiddenField(): void
    {
        $directive = new MethodDirective();

        self::assertSame('method', $directive->name());

        $output = $directive->compile("'PUT'");

        self::assertStringContainsString('_method', $output);
        self::assertStringContainsString('hidden', $output);
        self::assertStringContainsString('htmlspecialchars', $output);
    }

    // --- i18n Directive Tests ---

    #[Test]
    public function i18nDirectiveCompilesCorrectly(): void
    {
        $directive = new I18nDirective();

        self::assertSame('i18n', $directive->name());

        $output = $directive->compile("'messages.welcome'");

        self::assertStringContainsString('__', $output);
        self::assertStringContainsString('htmlspecialchars', $output);
        self::assertStringContainsString("'messages.welcome'", $output);
    }

    #[Test]
    public function i18nDirectiveWithParametersCompilesCorrectly(): void
    {
        $directive = new I18nDirective();

        $output = $directive->compile("'messages.hello', ['name' => \$name]");

        self::assertStringContainsString("'messages.hello', ['name' => \$name]", $output);
    }

    // --- PHP Directive Tests ---

    #[Test]
    public function phpDirectiveThrowsWhenDisabled(): void
    {
        $config = new ViewConfig(
            templatePaths: ['/views'],
            cachePath: '/cache',
            phpDirectiveAllowed: false,
        );

        $directive = new PhpDirective($config);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/@php/');

        $directive->compile('');
    }

    #[Test]
    public function phpDirectiveCompilesWhenEnabled(): void
    {
        $config = new ViewConfig(
            templatePaths: ['/views'],
            cachePath: '/cache',
            phpDirectiveAllowed: true,
        );

        $directive = new PhpDirective($config);

        $output = $directive->compile('');

        self::assertStringContainsString('<?php', $output);
    }
}
