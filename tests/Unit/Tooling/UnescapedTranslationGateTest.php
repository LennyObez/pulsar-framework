<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;
use Pulsar\Tests\Unit\Tooling\Support\TemporaryTree;

/**
 * Watches the unescaped-translation gate refuse.
 *
 * `tools/ci/assert-no-unescaped-translation.php` reads every `*.pulse.php` in a
 * tree and reports each place a translated string would reach the response body
 * without being escaped. It has existed, complete and correct, running nowhere:
 * no `composer qa` leaf and no workflow step invokes it, which is how
 * `PipelineGateCoverageTest` found it. A gate nobody runs and nobody has watched
 * refuse is two separate absences, and this closes the second one.
 *
 * The trap the gate exists for is that the same seven characters — `@t('k')` —
 * escape or do not escape depending only on what surrounds them. In markup the
 * `@` is the directive marker and the result is escaped; inside the template's
 * own PHP tags, or inside another directive's argument list, the `@` is PHP's
 * error-suppression operator, the helper is called directly, and the catalog
 * string goes out verbatim. Each of those three surroundings is planted below,
 * because a gate that caught only one of them would look identical from here.
 *
 * The acceptance case is not a formality either. Without it, every refusal below
 * is equally well explained by a gate that refuses every template it is shown,
 * which would be discovered the first time it ran over the repository and
 * switched off the same day.
 *
 * Note for whoever wires this into the pipeline: over this repository as it
 * stands the gate reports 346 sites in 29 templates, all of them under
 * `extensions/cms` and `extensions/forum`. That is the gate working, not the gate
 * misfiring — every one of those sites is a translated string reaching the
 * response unescaped — but it means switching the gate on turns the build red
 * until they are rewritten.
 */
#[CoversNothing]
#[GuardsGate(gate: 'tools/ci/assert-no-unescaped-translation.php', plants: 'a helper called inside a PHP tag, one inside a directive argument list, one echoed through {!! !!}, a root that is not a directory, and a root holding no template at all')]
final class UnescapedTranslationGateTest extends TestCase
{
    use InvokesCiScript;

    private const string SCRIPT = __DIR__ . '/../../../tools/ci/assert-no-unescaped-translation.php';

    /** @var list<TemporaryTree> */
    private array $trees = [];

    protected function tearDown(): void
    {
        foreach ($this->trees as $tree) {
            $tree->remove();
            self::assertDirectoryDoesNotExist($tree->path, 'a planted template tree survived the test');
        }

        $this->trees = [];
    }

    /**
     * The control: the three forms that DO escape are accepted.
     *
     * `@t` in markup compiles through the directive, `{{ t() }}` through the echo
     * escaper, and `@tRaw` is the deliberate, greppable opt-out. If this fails the
     * refusals below say nothing about the rule — only that the gate refuses.
     */
    #[Test]
    public function itAcceptsTheThreeFormsThatEscape(): void
    {
        $tree = $this->plant('accepted', [
            'views/page.pulse.php' => "<h1>@t('page.title')</h1>\n"
                . "<p>{{ t('page.body') }}</p>\n"
                . "<footer>@tRaw('page.footer_html')</footer>\n",
        ]);

        [$status, $stdout, $stderr] = $this->runScript(self::SCRIPT, '--root=' . $tree->path);

        self::assertSame(0, $status, $stdout . $stderr);
        self::assertStringContainsString('1 Pulse template(s) scanned', $stdout);
    }

    /**
     * A helper inside the template's own PHP tag: the `@` is suppression, not the
     * directive, and `t()` returns the catalog string straight into the output.
     */
    #[Test]
    public function itRefusesAHelperCalledInsideAPhpTag(): void
    {
        $tree = $this->plant('php-tag', [
            'views/greeting.pulse.php' => "<h1><?= @t('greeting') ?></h1>\n",
        ]);

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--root=' . $tree->path);

        self::assertSame(
            1,
            $status,
            'The gate accepted `<?= @t(...) ?>`. What ships on that silence: every translated '
            . 'string written that way reaches the response body unescaped, so a catalog entry '
            . 'containing markup — or a translation an editor can supply — is stored XSS, in a '
            . 'construct that reads exactly like the escaping one.',
        );
        self::assertStringContainsString('views/greeting.pulse.php:1', $stderr);
        self::assertStringContainsString('@t(', $stderr);
    }

    /**
     * A helper inside another directive's argument list: stored by `@section` and
     * echoed later by `@yield`, which does not escape.
     */
    #[Test]
    public function itRefusesAHelperInsideADirectiveArgumentList(): void
    {
        $tree = $this->plant('directive-argument', [
            'views/layout.pulse.php' => "@section('title', @t('page.title'))\n",
        ]);

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--root=' . $tree->path);

        self::assertSame(1, $status, 'the gate accepted a translation helper inside @section(...)');
        self::assertStringContainsString('views/layout.pulse.php:1', $stderr);
        self::assertStringContainsString('directive argument list', $stderr);
    }

    /**
     * The raw echo. `{!! !!}` emits its expression verbatim by design, so routing a
     * translation through it is unescaped output whatever the surrounding syntax.
     */
    #[Test]
    public function itRefusesATranslationEchoedThroughTheRawEcho(): void
    {
        $tree = $this->plant('raw-echo', [
            'views/banner.pulse.php' => "<p>{!! t('banner.text') !!}</p>\n",
        ]);

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--root=' . $tree->path);

        self::assertSame(1, $status, 'the gate accepted a translation echoed through {!! !!}');
        self::assertStringContainsString('views/banner.pulse.php:1', $stderr);
    }

    /**
     * A root that is not a directory is an invocation fault, and must not read as a
     * clean tree.
     *
     * Exit 2 rather than 1, so a step or a reader can tell "this gate could not run"
     * from "this gate found something" — and neither of them from success.
     */
    #[Test]
    public function itRefusesARootThatIsNotADirectory(): void
    {
        $tree = $this->plant('bad-root', ['views/page.pulse.php' => "<h1>@t('a')</h1>\n"]);
        $file = $tree->path . '/views/page.pulse.php';

        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--root=' . $file);

        self::assertSame(2, $status, 'a root that is a file was treated as a tree with nothing wrong in it');
        self::assertStringContainsString('is not a directory', $stderr);
    }

    /**
     * A scan that reached no template must not report the same result as a clean one.
     *
     * This is the failure mode the audit found nine times over: the gate still runs,
     * still exits 0, and is checking nothing — a moved root, a skip list that grew, a
     * renamed suffix. From outside, a repository whose templates are all correct and a
     * repository the scan never reached look identical, and only the count can tell
     * them apart.
     */
    #[Test]
    public function itRefusesAScanThatReachedNoTemplateAtAll(): void
    {
        $tree = $this->plant('no-templates', ['views/readme.md' => "not a template\n"]);

        [$status, $stdout, $stderr] = $this->runScript(self::SCRIPT, '--root=' . $tree->path);

        self::assertSame(
            2,
            $status,
            'The gate reported success over a tree holding no `*.pulse.php` at all. What ships '
            . 'on that silence: the gate surviving its own root moving, its skip list growing or '
            . 'the template suffix changing, green the whole time, exactly the way nine gates in '
            . 'this repository were found to be.',
        );
        self::assertStringContainsString('checked nothing', $stderr);
        self::assertStringNotContainsString('OK:', $stdout);
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    private function plant(string $label, array $files): TemporaryTree
    {
        $tree = TemporaryTree::create('unescaped-translation-' . $label);
        $this->trees[] = $tree;

        foreach ($files as $relative => $contents) {
            $tree->write($relative, $contents);
        }

        return $tree;
    }
}
