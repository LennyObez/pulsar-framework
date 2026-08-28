<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function file_get_contents;
use function json_decode;
use function str_replace;

use const JSON_THROW_ON_ERROR;

/**
 * Plants what the four pnpm gates exist to refuse, and observes each refusal.
 *
 * `pnpm lint`, `pnpm format:check` and `pnpm typecheck` are three steps of
 * .github/workflows/ci.yml and three lines of scripts/qa, and nothing had ever
 * watched any of them fail. That matters more here than for the PHP gates, because
 * scripts/qa skips all of them outright when node_modules is absent and still prints
 * "All checks passed" — so a local run has always been able to report success having
 * covered none of the JavaScript half.
 *
 * Each gate is driven through its real configuration:
 *
 *   pnpm lint .......... eslint.config.js, via --stdin-filename so the flat config
 *                        resolves rules for a path inside the repository without a
 *                        file being written into it.
 *   pnpm format:check .. .prettierrc.json, via --stdin-filepath, same reason.
 *   pnpm typecheck ..... tsconfig.json, via a fixture project that `extends` it, so
 *                        every strictness switch under test is the repository's own.
 *
 * The typecheck fixture violates one switch each, because `strict` alone is five
 * settings and the four beside it were each enabled deliberately: a diff dropping
 * `exactOptionalPropertyTypes` would leave `pnpm typecheck` green and silently allow
 * `{ label: undefined }` where the type says the property is absent.
 */
#[GuardsGate(gate: 'pnpm lint', plants: 'a declared-and-never-read binding that @typescript-eslint/no-unused-vars must refuse')]
#[GuardsGate(gate: 'pnpm format:check', plants: 'double quotes, a missing trailing comma and a missing semicolon against the committed .prettierrc.json')]
#[GuardsGate(gate: 'pnpm typecheck', plants: 'one violation each of strict, noUncheckedIndexedAccess, exactOptionalPropertyTypes, noImplicitOverride and noFallthroughCasesInSwitch')]
final class FrontendGateTest extends TestCase
{
    use PlantsDefectsForGates;

    /**
     * Any path inside the repository that the flat config has a rule set for. No
     * file is written here — ESLint and Prettier use the name only to decide which
     * configuration applies.
     */
    private const string LINTED_PATH = 'resources/ui/js/pulsar-gate-probe.ts';

    /** One violation per strictness switch, and the TypeScript code that names it. */
    private const array TYPE_ERRORS = [
        'TS18047' => 'strict / strictNullChecks is off: a possibly-null value may be dereferenced',
        'TS2322' => 'noUncheckedIndexedAccess is off: an array index may be treated as always present, '
            . 'which is how an undefined slips into a value typed as a string',
        'TS2375' => 'exactOptionalPropertyTypes is off: an explicit `undefined` may be assigned where '
            . 'the type says the property is absent',
        'TS4114' => 'noImplicitOverride is off: a method may silently shadow a base-class member, so a '
            . 'rename in the base leaves the subclass overriding nothing',
        'TS7029' => 'noFallthroughCasesInSwitch is off: a switch case may fall through unintentionally',
    ];

    private const string DRIFTED_TS = <<<'TS'
        export function len(value: string | null): number {
          return value.length;
        }

        export function first(values: string[]): string {
          return values[0];
        }

        export interface Optional {
          label?: string;
        }

        export const explicit: Optional = { label: undefined };

        class Base {
          greet(): string {
            return 'base';
          }
        }

        export class Child extends Base {
          greet(): string {
            return 'child';
          }
        }

        export function pick(n: number): string {
          switch (n) {
            case 1:
              console.log('one');
            case 2:
              return 'two';
            default:
              return 'other';
          }
        }
        TS;

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();
    }

    #[Test]
    public function lintRefusesAnUnusedBindingAndAcceptsTheUnderscoreConvention(): void
    {
        [$status, $stdout] = $this->runCommand(
            ['node', 'node_modules/eslint/bin/eslint.js', '--stdin', '--stdin-filename=' . self::LINTED_PATH, '--quiet', '--format=json'],
            stdin: "export function probe(): number {\n  const unused = 1;\n  return 2;\n}\n",
        );

        self::assertSame(
            1,
            $status,
            "pnpm lint accepted a declared-and-never-read binding. Had it stayed silent, either\n"
            . "eslint.config.js no longer applies @typescript-eslint/no-unused-vars at error severity,\n"
            . "or --quiet is now swallowing it — and dead bindings, unreachable imports and typo'd\n"
            . 'identifiers would all ship unremarked.' . $stdout,
        );
        self::assertStringContainsString('@typescript-eslint/no-unused-vars', $stdout);

        // The other direction: the configured argsIgnorePattern must still work, or
        // the refusal above is a gate that fails on everything.
        [$underscored] = $this->runCommand(
            ['node', 'node_modules/eslint/bin/eslint.js', '--stdin', '--stdin-filename=' . self::LINTED_PATH, '--quiet', '--format=json'],
            stdin: "export function probe(): number {\n  const _unused = 1;\n  return 2;\n}\n",
        );

        self::assertSame(
            0,
            $underscored,
            'eslint now refuses the `_`-prefixed convention its own varsIgnorePattern declares, so the '
            . 'refusal above says nothing about no-unused-vars in particular',
        );
    }

    #[Test]
    public function formatCheckRefusesDriftFromTheCommittedPrettierSettings(): void
    {
        // Double quotes where singleQuote is true, no trailing comma where
        // trailingComma is "all", no semicolon where semi is true.
        [$status, $stdout] = $this->prettier("export const drifted = {\n  a: 1,\n  b: \"two\"\n}\n");

        self::assertSame(
            1,
            $status,
            "pnpm format:check accepted double quotes, a missing trailing comma and a missing\n"
            . "semicolon. Had it stayed silent, .prettierrc.json would no longer be in force and every\n"
            . "editor's defaults would land in the tree, turning the next formatting pass into a diff\n"
            . 'nobody can review.' . $stdout,
        );
        self::assertStringContainsString('(stdin)', $stdout);

        [$clean] = $this->prettier("export const clean = { a: 1, b: 'two' };\n");

        self::assertSame(
            0,
            $clean,
            'prettier refuses its own output, so the refusal above is not evidence about the settings',
        );
    }

    #[Test]
    public function typecheckRefusesEveryStrictnessSwitchTheConfigurationEnables(): void
    {
        $project = $this->plantTypeScriptProject();

        [$status, $stdout] = $this->runCommand([
            'node',
            'node_modules/typescript/bin/tsc',
            '--noEmit',
            '-p',
            $project . '/tsconfig.json',
        ]);

        self::assertNotSame(
            0,
            $status,
            "pnpm typecheck accepted a file that violates five of tsconfig.json's strictness switches.\n"
            . "Had it stayed silent, `strict` would no longer be in force and the whole TypeScript half\n"
            . 'of the codebase would be checked as if it were JavaScript with annotations.' . $stdout,
        );

        foreach (self::TYPE_ERRORS as $code => $consequence) {
            self::assertStringContainsString(
                'error ' . $code . ':',
                $stdout,
                'tsc no longer reports ' . $code . ': ' . $consequence,
            );
        }
    }

    /**
     * `pnpm typecheck` is six invocations chained with `&&`, one per tsconfig. The
     * fixture above exercises the root one; the other five are frontend trees whose
     * only protection is that the chain still names them. Dropping one is a silent
     * removal of that tree from type checking, and the script keeps exiting 0.
     */
    #[Test]
    public function theTypecheckScriptStillNamesEveryFrontendProject(): void
    {
        $script = $this->packageScript('typecheck');

        foreach ([
            'extensions/analytics/frontend/tsconfig.json',
            'extensions/analytics/frontend/tracker/tsconfig.json',
            'extensions/cms/frontend/tsconfig.json',
            'extensions/admin/frontend/tsconfig.json',
            'extensions/studio/frontend/tsconfig.json',
        ] as $project) {
            self::assertStringContainsString(
                '-p ' . $project,
                $script,
                $project . ' has dropped out of the typecheck chain: that frontend is no longer type '
                . 'checked by anything, and pnpm typecheck keeps exiting 0 over it',
            );
        }
    }

    /**
     * `eslint .` and `prettier --check .` take the whole tree as their argument, so
     * what they cover is decided by their ignore lists. A directory added to one is
     * a directory that silently stops being checked — the stdin-driven refusals
     * above cannot see that, because a named path bypasses the ignore list.
     */
    #[Test]
    public function theLintAndFormatScriptsStillScanTheWholeTree(): void
    {
        self::assertStringContainsString(
            'eslint .',
            $this->packageScript('lint'),
            'pnpm lint no longer scans the repository root, so whatever it scans instead is the only '
            . 'thing linted, and nothing records what that is',
        );
        self::assertStringContainsString(
            'prettier --check .',
            $this->packageScript('format:check'),
            'pnpm format:check no longer scans the repository root',
        );
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function prettier(string $source): array
    {
        return $this->runCommand(
            ['node', 'node_modules/prettier/bin/prettier.cjs', '--check', '--stdin-filepath', self::LINTED_PATH],
            stdin: $source,
        );
    }

    /**
     * A project whose tsconfig `extends` the repository's, so every compiler option
     * under test is the committed one and only the file list differs.
     */
    private function plantTypeScriptProject(): string
    {
        $project = $this->plantTree('typecheck');
        $base = str_replace('\\', '/', $this->repositoryRoot()) . '/tsconfig.json';

        $this->plantFile($project, 'package.json', '{"type":"module"}' . "\n");
        $this->plantFile($project, 'probe.ts', self::DRIFTED_TS);
        $this->plantFile(
            $project,
            'tsconfig.json',
            '{"extends":"' . $base . '","include":["probe.ts"],"exclude":[]}' . "\n",
        );

        return $project;
    }

    private function packageScript(string $name): string
    {
        $raw = file_get_contents($this->repositoryRoot() . '/package.json');

        self::assertIsString($raw);

        /** @var mixed $manifest */
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertIsArray($manifest['scripts'] ?? null);
        self::assertArrayHasKey($name, $manifest['scripts'], 'package.json no longer declares a ' . $name . ' script');
        self::assertIsString($manifest['scripts'][$name]);

        return $manifest['scripts'][$name];
    }
}
