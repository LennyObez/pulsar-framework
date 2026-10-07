<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_diff;
use function array_keys;
use function array_values;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_file;
use function preg_match;
use function preg_match_all;
use function sort;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const PREG_OFFSET_CAPTURE;

/**
 * Every console command the code declares must be named in the CLI reference.
 *
 * `docs/cli-reference.md` documented 52 commands out of 152. The other hundred were
 * not marked as out of scope, not listed anywhere, not mentioned: the page simply
 * stopped, and a reader had no way to distinguish "Pulsar has no command for this"
 * from "this page does not cover it". A reference that silently omits two thirds of
 * its subject is worse than one that says what it covers, because it answers
 * questions it has no basis to answer.
 *
 * The page now carries a complete index and a stated scope. This test is what keeps
 * that true: the index is generated from the same declarations the console reads, so
 * the moment a command is added without a row, the build fails and names it.
 *
 * ## What is judged
 *
 * A class extending `Pulsar\Console\Command` that assigns `$this->name` a string
 * literal in `configure()`. That is how every command in this repository declares
 * itself, and it is what `Application` dispatches on.
 *
 * The index must NAME each of them, in a table cell, as `` `name` ``. It is not
 * required to give each one a `#### ` section — the reference explicitly documents
 * a subset in depth, and the index's own column says which. Requiring a full section
 * per command would convert a real gate into an unpayable tax and get it deleted.
 *
 * The reverse direction is judged too: an index row for a command that no longer
 * exists is the same defect pointed the other way, and is how `scaffold:module`
 * survived in this page for several releases after the command was removed.
 */
#[CoversNothing]
final class DocumentedCliCommandsTest extends FilesystemTestCase
{
    use PlantsFiles;

    /** Directories under a scan root that never hold shipping command classes. */
    private const array SKIPPED_DIRECTORIES = [
        '.git',
        'build',
        'coverage',
        'dist',
        'node_modules',
        'tests',
        'var',
        'vendor',
        'vendor-bin',
    ];

    /** Trees scanned for command declarations, relative to the repository root. */
    private const array SCAN_ROOTS = ['src', 'extensions'];

    private const string REFERENCE = 'docs/cli-reference.md';

    /** The heading that opens the table this gate keeps complete. */
    private const string INDEX_HEADING = '/^### Complete command index$/m';

    #[Test]
    public function theReferenceNamesEveryCommandTheCodeDeclares(): void
    {
        $root = dirname(__DIR__, 3);

        $missing = array_values(array_diff(
            self::declaredCommands($root),
            self::referencedCommands($root),
        ));

        self::assertSame(
            [],
            $missing,
            sprintf(
                "%d command(s) exist that %s does not name:\n  %s\n\n"
                . 'A reader searching the reference for one of these finds nothing and concludes the '
                . 'framework cannot do it. Add a row to the "Complete command index" table — a name and '
                . 'a description is enough; a full section is optional.',
                count($missing),
                self::REFERENCE,
                implode("\n  ", $missing),
            ),
        );
    }

    #[Test]
    public function theReferenceNamesNoCommandTheCodeHasRemoved(): void
    {
        $root = dirname(__DIR__, 3);

        $phantom = array_values(array_diff(
            self::referencedCommands($root),
            self::declaredCommands($root),
        ));

        self::assertSame(
            [],
            $phantom,
            sprintf(
                "%s names %d command(s) that no longer exist:\n  %s\n\n"
                . 'A reader who copies one gets `Unknown command`. Remove the row, or restore the '
                . 'command.',
                self::REFERENCE,
                count($phantom),
                implode("\n  ", $phantom),
            ),
        );
    }

    /**
     * Both scans have to find what is really there, or the two assertions above
     * are satisfied forever by a pair of empty sets.
     */
    #[Test]
    public function bothScansReadTheRealRepository(): void
    {
        $root = dirname(__DIR__, 3);

        $declared = self::declaredCommands($root);
        $referenced = self::referencedCommands($root);

        self::assertContains(
            'migrate:run',
            $declared,
            'The code scan found no `migrate:run`, which src/Console/Command/MigrateRunCommand.php '
            . 'declares. It is no longer reading command classes.',
        );
        self::assertGreaterThan(
            100,
            count($declared),
            'The code scan found implausibly few commands; the repository ships over 150.',
        );
        self::assertContains(
            'migrate:run',
            $referenced,
            'The documentation scan found no `migrate:run` in the CLI reference, which its index '
            . 'carries. It is no longer reading the page.',
        );
    }

    /**
     * Plant the shape that shipped — a command with no row — and watch it reported.
     */
    #[Test]
    #[GuardsGate(
        gate: 'DocumentedCliCommandsTest::theReferenceNamesEveryCommandTheCodeDeclares',
        plants: 'a repository declaring two commands whose reference indexes only one of them',
    )]
    public function itReportsACommandTheReferenceDoesNotName(): void
    {
        $root = $this->tempDirectory;

        $this->plantCommand($root, 'src/Console/Command/ListCommand.php', 'ListCommand', 'list');
        $this->plantCommand($root, 'src/Console/Command/DbSeedCommand.php', 'DbSeedCommand', 'db:seed');
        $this->plant(
            $root,
            self::REFERENCE,
            "# CLI reference\n\n### Complete command index\n\n| `list` | List commands |\n",
        );

        $missing = array_values(array_diff(
            self::declaredCommands($root),
            self::referencedCommands($root),
        ));

        self::assertSame(['db:seed'], $missing);
    }

    /**
     * And the other direction: a row surviving the command's removal.
     */
    #[Test]
    #[GuardsGate(
        gate: 'DocumentedCliCommandsTest::theReferenceNamesNoCommandTheCodeHasRemoved',
        plants: 'the `scaffold:module` row exactly as it shipped, beside the `make:module` that replaced it',
    )]
    public function itReportsAReferencedCommandThatNoLongerExists(): void
    {
        $root = $this->tempDirectory;

        $this->plantCommand($root, 'src/Console/Command/Make/MakeModuleCommand.php', 'MakeModuleCommand', 'make:module');
        $this->plant(
            $root,
            self::REFERENCE,
            "# CLI reference\n\n### Complete command index\n\n"
            . "| `make:module` | Scaffold a module |\n| `scaffold:module` | Scaffold a module |\n",
        );

        $phantom = array_values(array_diff(
            self::referencedCommands($root),
            self::declaredCommands($root),
        ));

        self::assertSame(['scaffold:module'], $phantom);
    }

    /**
     * Prose naming a command is not an index row, and must not satisfy the gate.
     *
     * The page deliberately records corrections in prose — "there is no
     * `scaffold:module`" — and a scan that counted those would let a command be
     * "documented" by a sentence saying it does not exist.
     */
    #[Test]
    #[GuardsGate(
        gate: 'DocumentedCliCommandsTest::theReferenceNamesEveryCommandTheCodeDeclares',
        plants: 'a command named only in prose, in a detailed section, and in an argument table — never in the index',
    )]
    public function proseOutsideTheIndexDoesNotCountAsNaming(): void
    {
        $root = $this->tempDirectory;

        $this->plantCommand($root, 'src/Console/Command/DbSeedCommand.php', 'DbSeedCommand', 'db:seed');
        $this->plant(
            $root,
            self::REFERENCE,
            "# CLI reference\n\nRun `db:seed` to seed the database.\n\n"
            . "#### `db:seed`\n\nRun database seeders.\n\n"
            . "| Argument | Required |\n| --- | --- |\n| `db:seed` | No |\n\n"
            . "### Complete command index\n\n| Command |\n| --- |\n",
        );

        self::assertSame(
            ['db:seed'],
            array_values(array_diff(self::declaredCommands($root), self::referencedCommands($root))),
            'A sentence, a detailed section and a row in some other table each named the command; '
            . 'none of them is an index row, and the index is what must stay complete.',
        );
    }

    /**
     * A scan reading nothing must be visible as reading nothing.
     *
     * This is the failure mode `bothScansReadTheRealRepository` exists to catch, and the
     * one that made nine gates in this repository green while checking nothing: the scan
     * stops matching, both sides come back empty, and `array_diff([], [])` is `[]`.
     */
    #[Test]
    #[GuardsGate(
        gate: 'DocumentedCliCommandsTest::bothScansReadTheRealRepository',
        plants: 'a tree with no command classes and a reference with no index, so both scans return nothing',
    )]
    public function itReportsEmptyScansRatherThanAgreement(): void
    {
        $root = $this->tempDirectory;

        $this->plant($root, 'src/Console/Command/NotACommand.php', "<?php\n\nfinal class NotACommand {}\n");
        $this->plant($root, self::REFERENCE, "# CLI reference\n\nNothing here.\n");

        self::assertSame([], self::declaredCommands($root));
        self::assertSame([], self::referencedCommands($root));

        // Both empty, so the two completeness assertions agree — and mean nothing.
        // That is exactly why the liveness rule asserts on content, not on the diff.
        self::assertSame(
            [],
            array_diff(self::declaredCommands($root), self::referencedCommands($root)),
            'Two empty scans agree. The liveness rule is what stops that agreement being '
            . 'mistaken for coverage.',
        );
    }

    /**
     * Deleting the index must not be a way to satisfy the gate.
     */
    #[Test]
    #[GuardsGate(
        gate: 'DocumentedCliCommandsTest::theReferenceNamesEveryCommandTheCodeDeclares',
        plants: 'a reference whose index heading has been deleted, leaving the rows behind',
    )]
    public function removingTheIndexHeadingReportsEveryCommandAsMissing(): void
    {
        $root = $this->tempDirectory;

        $this->plantCommand($root, 'src/Console/Command/DbSeedCommand.php', 'DbSeedCommand', 'db:seed');
        $this->plant(
            $root,
            self::REFERENCE,
            "# CLI reference\n\n| `db:seed` | Run database seeders |\n",
        );

        self::assertSame([], self::referencedCommands($root));
        self::assertSame(
            ['db:seed'],
            array_values(array_diff(self::declaredCommands($root), self::referencedCommands($root))),
        );
    }

    /**
     * Command names declared by classes extending the console `Command` base.
     *
     * @return list<string> sorted, unique
     */
    private static function declaredCommands(string $root): array
    {
        $names = [];

        foreach (self::SCAN_ROOTS as $relative) {
            $directory = $root . DIRECTORY_SEPARATOR . $relative;

            if (!is_dir($directory)) {
                continue;
            }

            foreach (self::phpFiles($directory) as $file) {
                $source = file_get_contents($file);

                if ($source === false) {
                    continue;
                }

                if (preg_match('/class\s+\w+\s+extends\s+Command\b/', $source) !== 1) {
                    continue;
                }

                if (preg_match('/\$this->name\s*=\s*\'([^\']+)\'/', $source, $match) === 1) {
                    $names[$match[1]] = true;
                }
            }
        }

        $list = array_keys($names);
        sort($list);

        /** @var list<string> $list */
        return $list;
    }

    /**
     * Command names listed in the reference's "Complete command index" table.
     *
     * Only that table counts. The page's other tables carry argument names
     * (`directory`, `queue`, `id`) and option names in the same backticked-cell
     * shape, and a scan that read them would call a command documented because
     * an unrelated command happens to take an argument by that name. Restricting
     * to the index also keeps the gate pointed at the thing that must stay
     * complete, rather than at the whole page.
     *
     * @return list<string> sorted, unique
     */
    private static function referencedCommands(string $root): array
    {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::REFERENCE);
        $markdown = is_file($path) ? file_get_contents($path) : false;

        if ($markdown === false) {
            return [];
        }

        $section = self::indexSection($markdown);
        $names = [];

        // A row's first cell: `| ` then a backticked name then ` |`. Anchored to the
        // pipes so a name in prose or in a fenced example is not read as an entry.
        preg_match_all('/^\|\s*`([a-z0-9][a-z0-9:_.-]*)`\s*\|/m', $section, $matches);

        foreach ($matches[1] as $name) {
            $names[$name] = true;
        }

        $list = array_keys($names);
        sort($list);

        /** @var list<string> $list */
        return $list;
    }

    /**
     * The markdown between the index heading and the next heading of any level.
     *
     * Returns the empty string when the heading is absent, which fails the
     * completeness assertion loudly rather than passing an empty comparison:
     * deleting the index must not be a way to satisfy this gate.
     */
    private static function indexSection(string $markdown): string
    {
        if (preg_match(self::INDEX_HEADING, $markdown, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $rest = substr($markdown, $start);

        if (preg_match('/^#{1,6} /m', $rest, $next, PREG_OFFSET_CAPTURE) === 1) {
            return substr($rest, 0, $next[0][1]);
        }

        return $rest;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        $pruned = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static function (mixed $entry): bool {
                // FilesystemIterator's flags decide whether current() hands back an
                // SplFileInfo, a pathname string, or the iterator itself. The flags
                // above give SplFileInfo, but narrowing here rather than in the
                // signature keeps that a fact the code checks instead of one it
                // assumes.
                if (!$entry instanceof SplFileInfo) {
                    return false;
                }

                if (!$entry->isDir()) {
                    return $entry->getExtension() === 'php';
                }

                return !in_array($entry->getFilename(), self::SKIPPED_DIRECTORIES, true);
            },
        );

        $paths = [];

        foreach (new RecursiveIteratorIterator($pruned) as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
                continue;
            }

            $paths[] = $entry->getPathname();
        }

        return $paths;
    }

    private function plantCommand(string $root, string $relativePath, string $class, string $name): void
    {
        $this->plant($root, $relativePath, sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class %s extends Command\n{\n"
            . "    protected function configure(): void\n    {\n"
            . "        \$this->name = '%s';\n    }\n}\n",
            $class,
            $name,
        ));
    }
}
