<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\DirectiveRegistry;
use Pulsar\View\Directive\FormDirective;
use Pulsar\View\ViewConfig;
use Pulsar\View\ViewException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function implode;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function pathinfo;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_split;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function strtoupper;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

/**
 * No template directive may emit an HTTP method-spoofing field.
 *
 * Pulsar dispatches on the request line alone. `Kernel::dispatchRoute()` reads
 * the verb from the `Method` enum and answers `501` for anything outside it;
 * nothing in `src/Http` or `src/Routing` reads a `_method` body field or an
 * `X-HTTP-Method-Override` header, which is the property
 * `docs/security/asvs-l2-matrix.md` claims under ASVS V14.5 — there is no
 * verb-tunnelling path into this framework.
 *
 * A directive that renders `<input name="_method" value="DELETE">` contradicts
 * that claim in the only place a form author looks. The field is never read, so
 * the form POSTs; a route registered for `DELETE` alone answers `405`, and a
 * path that also accepts `POST` runs the POST handler under whatever access
 * declaration IT carries. Either way the author is told a verb was sent that
 * never was. Honouring the field instead is a request-smuggling and CSRF
 * decision that needs its own ADR, so the field goes and the claim stands.
 */
#[CoversClass(DirectiveRegistry::class)]
#[CoversClass(FormDirective::class)]
final class NoMethodSpoofingTest extends TestCase
{
    #[Test]
    public function noBuiltInDirectiveCompilesToAMethodSpoofingField(): void
    {
        $registry = new DirectiveRegistry(new ViewConfig(templatePaths: [], cachePath: '', phpDirectiveAllowed: true));
        $registry->registerBuiltins();

        $names = $registry->names();

        self::assertNotEmpty($names);

        foreach ($names as $name) {
            $directive = $registry->get($name);

            self::assertNotNull($directive);

            // A verb argument is what a spoofing directive would act on, so it
            // is the expression most likely to produce the field.
            $compiled = $directive->compile("'DELETE'");

            // The rendered field, not the substring: `@form`'s generated code
            // legitimately holds a `$__form_method` local, and matching that
            // would make this assertion unfalsifiable noise.
            self::assertStringNotContainsString(
                'name="_method"',
                $compiled,
                "@{$name} compiles to a method-spoofing field nothing on the server reads",
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shippedTreesThatCanHoldMarkup(): iterable
    {
        yield 'src' => ['src'];
        yield 'resources' => ['resources'];
        yield 'examples' => ['examples'];
        yield 'docs' => ['docs'];
        yield 'extensions' => ['extensions'];
    }

    #[Test]
    #[DataProvider('shippedTreesThatCanHoldMarkup')]
    public function noShippedFileRendersAMethodSpoofingField(string $tree): void
    {
        // The directive was one spelling of the field; a hand-written
        // `<input type="hidden" name="_method">` in a view is the same untruth
        // typed out, and several bundled admin views carried one. Scanning the
        // shipped trees is what makes the removal survive: a directive test
        // alone would not have seen them.
        $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . $tree;

        self::assertDirectoryExists($root);

        $offenders = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            $markup = self::copyableMarkup($file->getPathname(), $contents);

            // The rendered field only. `$__form_method`, `filter_by_method` and
            // `code_challenge_method` are unrelated and must not trip this.
            if (str_contains($markup, 'name="_method"') || str_contains($markup, "name='_method'")) {
                $offenders[] = str_replace(dirname(__DIR__, 4) . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        self::assertSame(
            [],
            $offenders,
            'These files ship a `_method` field no Pulsar request path reads: ' . implode(', ', $offenders),
        );
    }

    /**
     * The part of a shipped file a template author can copy into a view.
     *
     * For everything except Markdown that is the whole file: a `.php`, `.html`,
     * `.js` or `.css` file under a shipped tree either carries the field or it
     * does not, and there is no second reading of it.
     *
     * Markdown is prose *about* the code, and one thing this repository asks of
     * its prose is that a record can name what it removed. ADR-0073 explains the
     * deleted `@method` directive by quoting the exact hidden input the
     * directive used to compile to; a record that cannot quote its own subject
     * is a worse artefact than the directive it retired. So one construct is
     * read as a citation rather than as markup: an inline code span, on a line
     * outside a fenced block.
     *
     * Nothing else is excused, and the narrowness is the point.
     *
     * - A fenced block is what a reader copies into a template, so it is scanned
     *   verbatim — including any backticks inside it, which is why the spans are
     *   stripped only outside a fence. A fenced example cannot launder itself.
     * - An indented block is never inside a fence either, so it is scanned.
     * - A bare sentence spelling the field out is instruction, not citation, and
     *   is scanned.
     *
     * A whole-tree exclusion for `docs/` would have been one line and would have
     * excused all four of those at once, which is the shape of the defect this
     * test exists to refuse.
     */
    private static function copyableMarkup(string $path, string $contents): string
    {
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'md') {
            return $contents;
        }

        $lines = preg_split('/\R/', $contents);

        if ($lines === false) {
            return $contents;
        }

        $fenceCharacter = null;
        $fenceLength = 0;
        $copyable = [];

        foreach ($lines as $line) {
            if ($fenceCharacter === null) {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $matches) === 1) {
                    $fenceCharacter = $matches[1][0];
                    $fenceLength = strlen($matches[1]);
                    $copyable[] = '';

                    continue;
                }

                // Outside a fence, an inline code span is a citation: drop it and keep
                // the sentence around it, so a prose line quoting the field survives
                // while the same field typed bare into that sentence does not.
                $copyable[] = (string) preg_replace('/`+[^`]*`+/', ' ', $line);

                continue;
            }

            $closer = '/^ {0,3}(' . preg_quote($fenceCharacter, '/') . '{3,})[[:blank:]]*$/';

            if (preg_match($closer, $line, $matches) === 1 && strlen($matches[1]) >= $fenceLength) {
                $fenceCharacter = null;
                $fenceLength = 0;
                $copyable[] = '';

                continue;
            }

            // Inside a fence nothing is stripped: this is the markup a reader lifts
            // into a template, backticks and all.
            $copyable[] = $line;
        }

        return implode("\n", $copyable);
    }

    #[Test]
    public function theMethodDirectiveIsNotRegistered(): void
    {
        $registry = new DirectiveRegistry(new ViewConfig(templatePaths: [], cachePath: '', phpDirectiveAllowed: true));
        $registry->registerBuiltins();

        self::assertFalse($registry->has('method'));
        self::assertNull($registry->get('method'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedVerbs(): iterable
    {
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
        yield 'lowercase delete' => ['delete'];
    }

    #[Test]
    #[DataProvider('unsupportedVerbs')]
    public function formDirectiveRefusesAVerbAnHtmlFormCannotSend(string $verb): void
    {
        // Refuse rather than degrade: rendering `<form method="POST">` for a
        // requested DELETE is the silent downgrade that made the spoofing field
        // look like it worked.
        $compiled = new FormDirective()->compile("\$dto, ['method' => '{$verb}']");

        $dto = new class {
            public string $title = '';
        };

        // eval() runs the directive's OWN generated PHP, built from a fixed
        // test-local expression rather than user input — the same way
        // ForeachDirectiveTest asserts compiled template behaviour.
        ob_start();

        try {
            eval('?>' . $compiled);

            ob_end_clean();
            self::fail("@form rendered method {$verb} instead of refusing it");
        } catch (ViewException $e) {
            ob_end_clean();

            self::assertStringContainsString('@form', $e->getMessage());
            self::assertStringContainsString('request line', $e->getMessage());
            self::assertStringContainsString($dto->title . strtoupper($verb), $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function supportedVerbs(): iterable
    {
        yield 'POST' => ['POST', 'POST'];
        yield 'GET' => ['GET', 'GET'];
        yield 'lowercase post' => ['post', 'POST'];
    }

    #[Test]
    #[DataProvider('supportedVerbs')]
    public function formDirectiveStillRendersTheVerbsAnHtmlFormCanSend(
        string $requested,
        string $rendered,
    ): void {
        $compiled = new FormDirective()->compile("\$dto, ['method' => '{$requested}', 'action' => '/submit']");

        $dto = new class {
            public string $title = '';
        };

        // eval() runs the directive's OWN generated PHP, built from a fixed
        // test-local expression rather than user input — the same way
        // ForeachDirectiveTest asserts compiled template behaviour.
        ob_start();
        eval('?>' . $compiled);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('method="' . $rendered . '"', $html);
        self::assertStringContainsString('action="/submit"', $html);
        self::assertStringNotContainsString('_method', $html);
        self::assertStringContainsString('name="title"', $html);
    }
}
