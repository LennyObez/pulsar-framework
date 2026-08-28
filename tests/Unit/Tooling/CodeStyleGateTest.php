<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function file_get_contents;
use function implode;
use function json_decode;
use function sprintf;

/**
 * Plants what `composer cs:check` exists to refuse, and observes the refusal.
 *
 * `php-cs-fixer fix --dry-run --diff` is the only gate in the repository whose
 * failing exit code is not 1 — it is 8, and a step that tested for 1 would read a
 * drifted tree as clean. So the code is asserted, not merely its non-zeroness.
 *
 * Beyond that, an exit code says only that SOMETHING would be rewritten. What is
 * actually at stake is the rule set: `.php-cs-fixer.dist.php` composes @PER-CS2.0,
 * its risky companion, the PHP 8.5 migration set and eleven explicit rules, and any
 * of those could be dropped without a single existing test noticing — the tree would
 * simply stop being reformatted in that one respect, and `cs:check` would stay green
 * over the drift it no longer looks for.
 *
 * The planted files therefore violate one rule from each group, and the assertion is
 * on the fixer names php-cs-fixer reports as applicable:
 *
 *   @PER-CS2.0 ............. braces_position, blank_line_after_opening_tag
 *   @PER-CS2.0:risky ....... strict_param  (dies with setRiskyAllowed(false))
 *   @PHP8x5Migration ....... nullable_type_declaration_for_default_null_value
 *   explicit rules ......... declare_strict_types, array_syntax, single_quote,
 *                            ordered_imports, no_unused_imports,
 *                            native_function_invocation, global_namespace_import
 *
 * The configuration is the repository's own; `--path-mode` is left at its default
 * `override` so the planted file is examined despite sitting outside the finder, and
 * {@see theFinderStillCoversEveryTreeThatCarriesFirstPartyPhp} covers the finder
 * separately, since an overridden path cannot exercise it.
 */
#[GuardsGate(gate: 'composer cs:check', plants: 'a file violating @PER-CS2.0, its risky companion, the PHP 8.5 migration set and seven of the explicit rules at once')]
final class CodeStyleGateTest extends TestCase
{
    use PlantsDefectsForGates;

    /**
     * php-cs-fixer's documented exit code for "some files need fixing". CI treats
     * any non-zero as failure; this is asserted exactly so a future step that
     * branches on `=== 1` cannot be introduced without something going red.
     */
    private const int NEEDS_FIXING = 8;

    /** Violates @PER-CS2.0 and seven of the explicit rules at once. */
    private const string DRIFTED = <<<'PHP'
        <?php
        namespace PulsarGateProbe;

        use Zeta\Later;
        use Alpha\Earlier;
        use Never\Used;

        final class Drifted
        {
            public function rows(int $n): array
            {
                if($n>0){
                    return array("first" => new Earlier(), "second" => new Later());
                }

                return [strlen("x")];
            }
        }
        PHP;

    /** Violates the risky set and the PHP 8.5 migration set, and nothing else. */
    private const string RISKY = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace PulsarGateProbe;

        use function in_array;
        use function intval;

        final class Risky
        {
            public function has(string $needle, array $haystack, string $label = null): int
            {
                return in_array($needle, $haystack) ? intval($label) : 0;
            }
        }
        PHP;

    /**
     * Fixer names the gate must still consider applicable, and what each one being
     * absent would mean for the tree.
     *
     * @var array<string, string>
     */
    private const array REQUIRED_FIXERS = [
        'braces_position' => '@PER-CS2.0 is no longer loaded: brace placement is unenforced',
        'blank_line_after_opening_tag' => '@PER-CS2.0 is no longer loaded: file preamble layout is unenforced',
        'strict_param' => 'setRiskyAllowed(false) or the risky set is gone: in_array() may ship '
            . 'without its strict flag, which is a loose-comparison bug the formatter used to prevent',
        'nullable_type_declaration_for_default_null_value' => 'the @PHP8x5Migration set is gone: the '
            . 'codebase may drift back to implicit-nullable parameters, deprecated since PHP 8.4',
        'declare_strict_types' => 'declare(strict_types=1) is no longer added, so a new file can ship '
            . 'in coercive mode and silently accept the wrong scalar types',
        'array_syntax' => 'long array() syntax may return',
        'single_quote' => 'double-quoted constant strings may return',
        'ordered_imports' => 'import order is unenforced, so every touched file re-sorts its own way',
        'no_unused_imports' => 'dead imports may accumulate',
        'native_function_invocation' => 'unqualified calls to compiler-optimised functions may return, '
            . 'losing the opcode specialisation the rule exists for',
        'global_namespace_import' => 'global symbols may be referenced fully qualified again',
    ];

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();
    }

    #[Test]
    public function itRefusesStyleDriftWithTheExitCodeCiBranchesOn(): void
    {
        $tree = $this->plantTree('cs-check');
        $this->plantFile($tree, 'Drifted.php', self::DRIFTED);

        [$status, $stdout] = $this->check($tree . '/Drifted.php');

        self::assertSame(
            self::NEEDS_FIXING,
            $status,
            "composer cs:check accepted a file with long array syntax, double-quoted constants,\n"
            . "unsorted and unused imports, no declare(strict_types=1) and PER-CS brace drift. Had it\n"
            . "stayed silent, all of that would ship — and the tree would diverge one commit at a time\n"
            . "until the next person to run cs:fix produces a diff nobody can review.\n" . $stdout,
        );
    }

    #[Test]
    public function everyRuleSetTheConfigurationComposesIsStillInForce(): void
    {
        $tree = $this->plantTree('cs-rulesets');
        $this->plantFile($tree, 'Drifted.php', self::DRIFTED);
        $this->plantFile($tree, 'Risky.php', self::RISKY);

        $applied = $this->appliedFixers($tree);

        foreach (self::REQUIRED_FIXERS as $fixer => $consequence) {
            self::assertContains(
                $fixer,
                $applied,
                sprintf(
                    "php-cs-fixer no longer applies %s to a file that violates it: %s.\nApplied: %s",
                    $fixer,
                    $consequence,
                    implode(', ', $applied),
                ),
            );
        }
    }

    /**
     * The finder is the one part of .php-cs-fixer.dist.php a run against an
     * explicit path cannot exercise, because such a path replaces it. A tree
     * dropping out of the finder is silent: cs:check keeps passing and simply stops
     * looking there, which is how tools/, scripts/ and benchmarks/ went unformatted
     * for as long as they did.
     */
    #[Test]
    public function theFinderStillCoversEveryTreeThatCarriesFirstPartyPhp(): void
    {
        $configuration = file_get_contents($this->repositoryRoot() . '/.php-cs-fixer.dist.php');

        self::assertIsString($configuration);

        foreach (['src', 'tests', 'config', 'extensions', 'tools', 'scripts', 'benchmarks'] as $tree) {
            self::assertStringContainsString(
                "->in(__DIR__ . '/" . $tree . "')",
                $configuration,
                $tree . '/ has dropped out of the php-cs-fixer finder: it is no longer formatted, '
                . 'and cs:check would stay green over any amount of drift inside it',
            );
        }
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function check(string $path): array
    {
        // --using-cache=no because the shared .php-cs-fixer.cache in the repository
        // root is a working-tree file, and a gate test must not write to one.
        return $this->runGate([
            'vendor/bin/php-cs-fixer',
            'fix',
            '--dry-run',
            '--diff',
            '--using-cache=no',
            $path,
        ]);
    }

    /**
     * @return list<string>
     */
    private function appliedFixers(string $tree): array
    {
        [$status, $stdout] = $this->runGate([
            'vendor/bin/php-cs-fixer',
            'fix',
            '--dry-run',
            '--using-cache=no',
            '--format=json',
            '-v',
            $tree,
        ]);

        self::assertSame(self::NEEDS_FIXING, $status, $stdout);

        /** @var mixed $report */
        $report = json_decode($stdout, true);

        self::assertIsArray($report);
        self::assertArrayHasKey('files', $report);

        $files = $report['files'];

        self::assertIsArray($files);
        self::assertNotSame([], $files, 'php-cs-fixer reported no files at all: ' . $stdout);

        $applied = [];

        foreach ($files as $file) {
            self::assertIsArray($file);
            self::assertArrayHasKey('appliedFixers', $file, 'php-cs-fixer reported no fixer names');

            $fixers = $file['appliedFixers'];

            self::assertIsArray($fixers);

            foreach ($fixers as $fixer) {
                self::assertIsString($fixer);
                $applied[] = $fixer;
            }
        }

        return $applied;
    }
}
