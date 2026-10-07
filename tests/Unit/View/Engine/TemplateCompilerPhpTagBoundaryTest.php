<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PhpToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\ViewConfig;

use function sys_get_temp_dir;

use const TOKEN_PARSE;

/**
 * Directive compilation stops at the template's PHP tag boundaries.
 *
 * A directive expands to a PHP block. Expanding one that already sits inside a
 * PHP tag could only ever nest one open tag inside another, which does not
 * parse — so every view written as a short echo of `@t('key')` produced a
 * compiled file PHP refuses to run. Inside a PHP tag `@` is not template syntax
 * at all: it is the error-suppression operator applied to the global helper.
 *
 * That boundary is still the rule, but leaving those runs alone silently is
 * not: an unexpanded `@t` inside a PHP tag calls the raw helper, which does not
 * escape, while the same characters in markup compile to an
 * `htmlspecialchars()` call. Translations reaching a PHP tag are now refused
 * outright — see {@see TranslationOutputGuardTest}. What this test pins is the
 * lexical boundary itself, on constructs that carry no translation.
 */
#[CoversClass(TemplateCompiler::class)]
final class TemplateCompilerPhpTagBoundaryTest extends TestCase
{
    private TemplateCompiler $compiler;

    protected function setUp(): void
    {
        $config = new ViewConfig(
            templatePaths: [sys_get_temp_dir()],
            cachePath: sys_get_temp_dir(),
        );

        $this->compiler = new TemplateCompiler($config, new TemplateCache(sys_get_temp_dir()));
        $this->compiler->registerDirective(
            't',
            static fn(string $expression): string => '<?php echo translated(' . $expression . '); ?>',
        );
        // A registered directive that carries no translation, so the lexical
        // boundary can be observed on a construct the translation guard has no
        // opinion about.
        $this->compiler->registerDirective(
            'shout',
            static fn(string $expression): string => '<?php echo shouted(' . $expression . '); ?>',
        );
    }

    /**
     * A non-translation directive name inside a PHP tag is still left to PHP:
     * the boundary is lexical, not a list of exceptions.
     */
    #[Test]
    public function directiveInsideAShortEchoTagIsLeftToPhp(): void
    {
        $source = '<h1><?= @shout($title) ?></h1>';

        $compiled = $this->compiler->compileSource($source);

        self::assertSame($source, $compiled);
        self::assertParses($compiled);
        self::assertSame(
            '<h1><?php echo shouted($title); ?></h1>',
            $this->compiler->compileSource('<h1>@shout($title)</h1>'),
            'the same directive in markup does compile, so the difference is the PHP tag',
        );
    }

    #[Test]
    public function directiveInsideALongPhpTagIsLeftToPhp(): void
    {
        $source = '<?php echo @shout($title); ?>';

        self::assertSame($source, $this->compiler->compileSource($source));
    }

    #[Test]
    public function directiveInMarkupStillCompiles(): void
    {
        $compiled = $this->compiler->compileSource("<h1>@t('page.title')</h1>");

        self::assertSame("<h1><?php echo translated('page.title'); ?></h1>", $compiled);
        self::assertParses($compiled);
    }

    /**
     * The boundary comes from PHP's own lexer, so a `?>` inside a string literal
     * does not end the PHP run — a hand-rolled tag scanner would have resumed
     * directive expansion in the middle of that string.
     */
    #[Test]
    public function aCloseTagInsideAStringLiteralDoesNotResumeDirectiveExpansion(): void
    {
        $source = "<?php \$marker = '?> @t(\"trap\")'; ?>@t('after')";

        $compiled = $this->compiler->compileSource($source);

        self::assertStringContainsString('@t("trap")', $compiled);
        self::assertStringContainsString("<?php echo translated('after'); ?>", $compiled);
        self::assertParses($compiled);
    }

    /**
     * Markup on both sides of a PHP run is still compiled: confining expansion
     * to the inline-HTML runs must not skip the runs themselves.
     */
    #[Test]
    public function markupAroundAPhpRunIsStillCompiled(): void
    {
        $compiled = $this->compiler->compileSource("@t('before')<?= \$x ?>@t('after')");

        self::assertSame(
            "<?php echo translated('before'); ?><?= \$x ?><?php echo translated('after'); ?>",
            $compiled,
        );
        self::assertParses($compiled);
    }

    /**
     * `TOKEN_PARSE` makes the lexer run the parser too, so invalid output raises
     * a ParseError and fails the test rather than returning a token list.
     */
    private static function assertParses(string $php): void
    {
        self::assertNotSame([], PhpToken::tokenize($php, TOKEN_PARSE), 'Compiled output must parse as PHP.');
    }
}
