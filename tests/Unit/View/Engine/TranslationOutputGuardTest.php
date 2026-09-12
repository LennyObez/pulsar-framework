<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\Engine\TranslationOutputGuard;
use Pulsar\View\Engine\TranslationOutputViolation;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;

use function count;
use function sys_get_temp_dir;

/**
 * The template layer admits exactly one escaped form per context and refuses
 * every construct that would put a catalog string on the wire unescaped.
 *
 * The finding this closes: 318 sites written as a short echo wrapping an
 * `@`-prefixed translation call. Inside a PHP tag that `@` is PHP's
 * error-suppression operator, not the directive marker, so `t()` is called
 * directly and returns the catalog string as-is — while the identical seven
 * characters in markup compile to an `htmlspecialchars()` call. A form whose
 * escaping depends on its surroundings cannot be reviewed by reading it.
 */
#[CoversClass(TranslationOutputGuard::class)]
#[CoversClass(TranslationOutputViolation::class)]
final class TranslationOutputGuardTest extends TestCase
{
    private TranslationOutputGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new TranslationOutputGuard();
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function refusedConstructs(): iterable
    {
        yield 'short echo of a suppressed helper' => ["<h1><?= @t('page.title') ?></h1>", 1];
        yield 'short echo of a bare helper' => ["<h1><?= t('page.title') ?></h1>", 1];
        yield 'long echo of a suppressed helper' => ["<?php echo @trans('page.title'); ?>", 1];
        yield 'long echo of the double-underscore helper' => ["<?php echo __('page.title'); ?>", 1];
        yield 'short echo of the raw helper' => ["<?= tRaw('page.title') ?>", 1];
        yield 'fully qualified helper' => ["<?= \\t('page.title') ?>", 1];
        yield 'helper assigned inside a PHP tag' => ["<?php \$title = t('page.title'); ?>", 1];
        yield 'helper inside a @php block' => ["@php echo t('page.title'); @endphp", 1];
        yield 'suppressed helper in a directive argument' => ["@section('title', @t('page.title'))", 1];
        yield 'suppressed helper in an escaped echo' => ["<p>{{ \$name ?? @t('anonymous') }}</p>", 1];
        yield 'suppressed helper in a raw echo' => ["<p>{!! @t('page.body') !!}</p>", 2];
        yield 'bare helper in a raw echo' => ["<p>{!! t('page.body') !!}</p>", 1];
        yield 'the i18n directive name inside a PHP tag' => ["<?= @i18n('page.title') ?>", 1];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedConstructs(): iterable
    {
        yield 'the directive in markup' => ["<h1>@t('page.title')</h1>"];
        yield 'the i18n directive in markup' => ["<h1>@i18n('page.title')</h1>"];
        yield 'the raw directive in markup' => ["<h1>@tRaw('page.body')</h1>"];
        yield 'a helper inside an escaped echo' => ["<h1>{{ t('page.title') }}</h1>"];
        yield 'a helper in a non-echoing directive argument' => ["@if(t('a') === \$b)x@endif"];
        yield 'a directive with no arguments' => ['@csrf'];
        yield 'an email address in markup' => ['<p>user@example.com</p>'];
        yield 'a commented-out violation' => ["{{-- <?= @t('page.title') ?> --}}"];
        yield 'a close tag inside a string literal' => ["<?php \$m = '?> @t(\"trap\")'; ?>"];
        yield 'a method named like the helper' => ["<?php echo \$svc->t('page.title'); ?>"];
        yield 'a static method named like the helper' => ["<?php echo Svc::t('page.title'); ?>"];
    }

    #[Test]
    #[DataProvider('refusedConstructs')]
    public function theGuardRefusesEveryUnescapedTranslationForm(string $source, int $expected): void
    {
        $violations = $this->guard->violations($source);

        self::assertCount(
            $expected,
            $violations,
            'Expected the guard to report this construct: ' . $source,
        );
    }

    #[Test]
    #[DataProvider('acceptedConstructs')]
    public function theGuardAcceptsEveryEscapedTranslationForm(string $source): void
    {
        self::assertSame(
            [],
            $this->guard->violations($source),
            'Expected the guard to accept this construct: ' . $source,
        );
    }

    /**
     * Line numbers are what makes a 318-site report actionable, so they are
     * asserted rather than assumed. `@php` normalisation rewrites the source
     * before the scan, and both replacements are newline-neutral for exactly
     * this reason.
     */
    #[Test]
    public function aViolationReportsTheLineItWasWrittenOn(): void
    {
        $source = "<h1>ok</h1>\n<p>ok</p>\n<?= @t('page.title') ?>\n@php echo t('x'); @endphp\n";

        $violations = $this->guard->violations($source);

        self::assertCount(2, $violations);
        self::assertSame(3, $violations[0]->line);
        self::assertSame(4, $violations[1]->line);
        self::assertSame('@t(', $violations[0]->construct);
    }

    /**
     * The message has to tell an author what to write instead; a diagnostic
     * that only says "no" moves the problem rather than closing it.
     */
    #[Test]
    public function theDiagnosticNamesTheReplacementForm(): void
    {
        $violations = $this->guard->violations("<?= @t('page.title') ?>");

        self::assertStringContainsString('error suppression', $violations[0]->reason);
        self::assertStringContainsString('@t directive in markup', $violations[0]->reason);
        self::assertStringContainsString('line 1', $violations[0]->describe());
    }

    /**
     * The compiler is where the refusal has to bite: a rule enforced only by a
     * CI script still renders the unsafe page on every developer machine.
     */
    #[Test]
    public function theCompilerRefusesToCompileTheUnsafeForm(): void
    {
        $compiler = $this->compiler();

        $this->expectException(ViewException::class);
        $this->expectExceptionMessageMatches('/unescaped translation output/');

        (void) $compiler->compileSource("<h1><?= @t('page.title') ?></h1>", 'forum::auth.login');
    }

    #[Test]
    public function theCompilerNamesTheTemplateAndTheLine(): void
    {
        $compiler = $this->compiler();

        try {
            (void) $compiler->compileSource("<h1>ok</h1>\n<?= @t('page.title') ?>", 'forum::auth.login');
            self::fail('Expected the compiler to refuse the unescaped form.');
        } catch (ViewException $e) {
            self::assertStringContainsString('forum::auth.login', $e->getMessage());
            self::assertStringContainsString('line 2', $e->getMessage());
        }
    }

    #[Test]
    public function theCompilerStillCompilesTheEscapedForms(): void
    {
        $compiler = $this->compiler();
        $compiler->registerDirective(
            't',
            static fn(string $expression): string => '<?php echo escaped(' . $expression . '); ?>',
        );

        $compiled = $compiler->compileSource("<h1>@t('page.title')</h1>{{ t('page.subtitle') }}");

        self::assertStringContainsString("escaped('page.title')", $compiled);
        self::assertStringContainsString("ContextEscaper::html((string) (t('page.subtitle')))", $compiled);
    }

    /**
     * Guards that stop at the first hit make a 318-site cleanup a 318-run
     * cleanup, so the guard reports the whole file in one pass.
     */
    #[Test]
    public function everySiteInAFileIsReportedInOnePass(): void
    {
        $source = "<?= @t('a') ?>\n<?= @t('b') ?>\n<?= @t('c') ?>\n";

        self::assertSame(3, count($this->guard->violations($source)));
    }

    private function compiler(): TemplateCompiler
    {
        $config = new ViewConfig(
            templatePaths: [sys_get_temp_dir()],
            cachePath: sys_get_temp_dir(),
        );

        return new TemplateCompiler($config, new TemplateCache(sys_get_temp_dir()));
    }
}
