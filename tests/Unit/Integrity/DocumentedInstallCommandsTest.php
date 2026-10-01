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

use function array_key_exists;
use function array_keys;
use function dirname;
use function fclose;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function preg_match;
use function preg_split;
use function proc_close;
use function proc_open;
use function rtrim;
use function sort;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * A documented install command must name a package this repository has.
 *
 * Four of them did not, and every one had been shipping for releases:
 *
 *   - `composer create-project pulsar/skeleton my-app` opened the getting-started
 *     guide. No package by that name has ever existed; a starter template is still
 *     only an open issue. It was the very first command a new reader ran.
 *   - `composer require pulsar/oauth2` and `composer require pulsar/webauthn`
 *     opened their respective guides. Both were merged into `pulsar/auth` before
 *     1.0.0 and neither was ever published, so both commands named packages that
 *     had stopped existing in the tree the docs shipped from.
 *   - `ComposerJsonGenerator` wrote `lennyobez/pulsar` into the `composer.json` of
 *     every project `pulsar init` scaffolded -- the name the framework had already
 *     migrated away from. That one is closed by
 *     {@see \Pulsar\Tests\Unit\Console\Command\NewProject\ComposerJsonGeneratorTest},
 *     which compares the emitted requirement against this repository's own name.
 *
 * `pulsar/framework` looks like the same defect and is not: it is the real package
 * name, it is what `composer.json` declares, and it 404s on Packagist only because
 * publication waits for the `1.0.0` tag. Documenting the eventual install command
 * for a release candidate is right. What separates it from the four above is
 * exactly what this ratchet tests -- whether the repository HAS the thing the
 * command names.
 *
 * ## What is judged, and what is deliberately not
 *
 * Only COMMANDS, and only inside a fenced shell block: that is what a reader
 * copies and runs. A sentence that mentions a dead package in backticks is prose,
 * and prose is how this repository records that an instruction used to be wrong --
 * `docs/webauthn.md` now says in as many words that it once told you to
 * `composer require pulsar/webauthn`. Banning the string outright would delete the
 * correction along with the defect, which is the mistake ADR-0067 names about
 * version strings, arriving here in another vocabulary.
 *
 * Only the two vendor namespaces this repository is answerable for are judged. A
 * doc may legitimately tell a reader to require `nyholm/psr7`, and whether that
 * package exists is Packagist's business, not this test's.
 */
#[CoversNothing]
final class DocumentedInstallCommandsTest extends FilesystemTestCase
{
    use PlantsFiles;

    /**
     * One scan per tree, kept for the life of the process.
     *
     * Five tests judge two trees between them; without this the repository is
     * read twice for no new information.
     *
     * @var array<string, array<string, string>>
     */
    private static array $scanned = [];

    /**
     * Vendor namespaces whose packages this repository is answerable for.
     *
     * @var list<string>
     */
    private const array OWNED_VENDORS = ['pulsar', 'lennyobez'];

    /**
     * Fenced-block languages whose contents a reader runs in a shell.
     *
     * @var list<string>
     */
    private const array SHELL_LANGUAGES = ['bash', 'sh', 'shell', 'console', 'zsh'];

    /**
     * Directory names never descended into when looking for documentation.
     *
     * @var list<string>
     */
    private const array SKIPPED_DIRECTORIES = [
        '.git',
        'build',
        'coverage',
        'dist',
        'node_modules',
        'storage',
        'var',
        'vendor',
        'vendor-bin',
    ];

    #[Test]
    public function everyDocumentedInstallCommandNamesAPackageThisRepositoryHas(): void
    {
        $violations = self::violations(dirname(__DIR__, 3));

        self::assertSame(
            [],
            $violations,
            "A documented command installs a package this repository does not have:\n  "
            . implode("\n  ", $violations)
            . "\n\nA reader who copies it gets `Could not find a matching version of package ...` and no "
            . 'way to tell a typo from a package that was renamed, merged, or never written. Name the '
            . 'real artifact, or say plainly that the thing does not exist yet and what to do instead.',
        );
    }

    /**
     * The scan has to find the commands that ARE there, or it proves nothing.
     *
     * Without this, a regex that matched nothing would satisfy the assertion above
     * forever, and the repository would carry a gate that had quietly stopped
     * reading its own documentation.
     */
    #[Test]
    public function theScanFindsTheInstallCommandsTheDocumentationActuallyCarries(): void
    {
        $found = self::documentedPackages(dirname(__DIR__, 3));

        self::assertContains(
            'pulsar/framework',
            $found,
            'The scan found no documented `composer require pulsar/framework`, which the installation '
            . 'guide and the getting-started guide both carry. It is no longer reading them, so its '
            . 'silence about bad package names means nothing.',
        );
    }

    /**
     * Plant each shape that shipped, and watch the scan report it.
     */
    #[Test]
    #[GuardsGate(
        gate: 'DocumentedInstallCommandsTest::everyDocumentedInstallCommandNamesAPackageThisRepositoryHas',
        plants: 'a fixture tree whose docs carry both shapes that shipped -- a `composer create-project` '
            . 'and a `composer require` naming packages the tree does not have -- and watches the scan '
            . 'name both, with the file and line a reader would have copied them from',
    )]
    public function itReportsAnInstallCommandNamingAPackageThatDoesNotExist(): void
    {
        $root = $this->tempDirectory;

        $this->plant($root, 'composer.json', '{"name": "pulsar/framework"}');
        $this->plant($root, 'extensions/auth/pulsar.json', '{"name": "pulsar/auth"}');
        $this->plant(
            $root,
            'docs/getting-started.md',
            "# Getting started\n\n```bash\ncomposer create-project pulsar/skeleton my-app\n```\n",
        );
        $this->plant(
            $root,
            'docs/webauthn.md',
            "# WebAuthn\n\n```bash\ncomposer require pulsar/webauthn\n```\n",
        );

        $violations = self::violations($root);

        self::assertCount(2, $violations, implode("\n", $violations));
        self::assertSame('docs/getting-started.md:4 names pulsar/skeleton', $violations[0]);
        self::assertSame('docs/webauthn.md:4 names pulsar/webauthn', $violations[1]);
    }

    /**
     * The half that keeps the ratchet from becoming a blanket ban.
     *
     * A doc saying "this page used to tell you to `composer require
     * pulsar/webauthn`" is the correction, not the defect, and a real package
     * named in a real command must pass. Both are asserted here, because a scan
     * that refused everything would satisfy the negative case above just as well.
     */
    #[Test]
    #[GuardsGate(
        gate: 'DocumentedInstallCommandsTest::everyDocumentedInstallCommandNamesAPackageThisRepositoryHas',
        plants: 'the refusal from the other side -- a doc naming a package that does not exist inside '
            . 'prose describing the correction, beside real packages in real commands -- so a scan that '
            . 'refused everything cannot pass for one that reads the difference',
    )]
    public function itLeavesProseAndRealPackagesAlone(): void
    {
        $root = $this->tempDirectory;

        $this->plant($root, 'composer.json', '{"name": "pulsar/framework"}');
        $this->plant($root, 'extensions/auth/composer.json', '{"name": "pulsar/auth"}');
        $this->plant(
            $root,
            'docs/webauthn.md',
            "# WebAuthn\n\nEarlier revisions told you to run `composer require pulsar/webauthn`, which\n"
            . "named a package that has never been published.\n\n"
            . "```bash\ncomposer require pulsar/framework\n```\n",
        );
        $this->plant($root, 'docs/psr7.md', "# PSR-7\n\n```bash\ncomposer require nyholm/psr7\n```\n");
        $this->plant($root, 'docs/auth.md', "# Auth\n\n```bash\ncomposer require pulsar/auth\n```\n");

        self::assertSame([], self::violations($root));
    }

    /**
     * A package named in a `php` block is documentation of a file, not a command.
     *
     * `docs/install.md` shows the `require` section of a generated composer.json,
     * and a reader does not run it. Judging fenced PHP or JSON as if it were a
     * shell would make the scan report the very files it exists to keep honest.
     */
    #[Test]
    #[GuardsGate(
        gate: 'DocumentedInstallCommandsTest::everyDocumentedInstallCommandNamesAPackageThisRepositoryHas',
        plants: 'a fenced `json` block naming a package the tree does not have -- documentation of a '
            . 'file rather than a command -- which the rule must not report, or it would refuse the '
            . 'install guide it exists to keep honest',
    )]
    public function itJudgesShellBlocksRatherThanEveryFencedBlock(): void
    {
        $root = $this->tempDirectory;

        $this->plant($root, 'composer.json', '{"name": "pulsar/framework"}');
        $this->plant(
            $root,
            'docs/install.md',
            "# Install\n\n```json\n{\"require\": {\"pulsar/skeleton\": \"^1.0\"}}\n```\n",
        );

        self::assertSame([], self::violations($root));
    }

    /**
     * Every documented install command in a tree that names a package it lacks.
     *
     * @return list<string> `path:line names vendor/package`, sorted
     */
    private static function violations(string $root): array
    {
        $available = self::packagesTheRepositoryHas($root);
        $violations = [];

        foreach (self::markdownFiles($root) as $relative => $contents) {
            foreach (self::installCommands($contents) as [$line, $package]) {
                if (array_key_exists($package, $available)) {
                    continue;
                }

                $violations[] = sprintf('%s:%d names %s', $relative, $line, $package);
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * Every package a documented install command asks for, whatever its state.
     *
     * @return list<string>
     */
    private static function documentedPackages(string $root): array
    {
        $packages = [];

        foreach (self::markdownFiles($root) as $contents) {
            foreach (self::installCommands($contents) as [, $package]) {
                $packages[$package] = true;
            }
        }

        $names = array_keys($packages);
        sort($names);

        return $names;
    }

    /**
     * The packages this repository defines: the framework and every bundled extension.
     *
     * Read out of the manifests rather than typed, so an extension added or renamed
     * next month is in scope without anybody remembering this file exists.
     *
     * @return array<string, true>
     */
    private static function packagesTheRepositoryHas(string $root): array
    {
        $names = [];

        foreach (self::manifestPaths($root) as $path) {
            $name = self::manifestName($path);

            if ($name !== null) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function manifestPaths(string $root): array
    {
        $paths = [$root . '/composer.json'];

        foreach (['/extensions/*/', '/extensions/*/*/'] as $depth) {
            foreach (['composer.json', 'pulsar.json'] as $manifest) {
                $found = glob($root . $depth . $manifest);

                if ($found === false) {
                    continue;
                }

                foreach ($found as $path) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * The `name` a manifest declares, or null when it has none to declare.
     */
    private static function manifestName(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        /** @var mixed $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            return null;
        }

        return $data['name'];
    }

    /**
     * Every documentation file in the tree, keyed by its path relative to the root.
     *
     * Discovery asks git, and that is a statement about scope rather than a speed
     * trick: this ratchet judges DOCUMENTATION THAT SHIPS, and what ships is what
     * the repository tracks. Walking the directory tree instead read
     * `.claude/plans/`, a gitignored scratch directory nobody publishes, and
     * reported a package name out of an internal planning note as a defect in the
     * docs. `--others --exclude-standard` keeps a doc that is written but not yet
     * committed in scope, so a bad instruction is caught in the change that
     * introduces it rather than the one after.
     *
     * It is also the difference between a gate that runs and one that gets skipped:
     * the walk spent minutes descending a synced filesystem before discarding
     * almost everything it found.
     *
     * @return array<string, string>
     */
    private static function markdownFiles(string $root): array
    {
        $normalized = str_replace('\\', '/', $root);

        if (array_key_exists($normalized, self::$scanned)) {
            return self::$scanned[$normalized];
        }

        $relativePaths = self::trackedMarkdown($normalized) ?? self::walkedMarkdown($normalized);
        $files = [];

        foreach ($relativePaths as $relative) {
            // is_file() first, deliberately. `git ls-files --cached` lists what the
            // INDEX holds, so a doc deleted in the working tree and not yet staged is
            // still named here; reading it emits a PHP warning, and the suite runs with
            // failOnWarning. The `=== false` arm below already meant to tolerate an
            // unreadable member -- this is what makes tolerating it silent.
            $absolute = $normalized . '/' . $relative;

            if (!is_file($absolute)) {
                continue;
            }

            $contents = file_get_contents($absolute);

            if ($contents === false) {
                continue;
            }

            $files[$relative] = $contents;
        }

        self::$scanned[$normalized] = $files;

        return $files;
    }

    /**
     * The markdown git knows about, or null when git cannot answer for this tree.
     *
     * The `rev-parse` guard matters: run inside a directory that is not itself a
     * work tree, git answers about the nearest ENCLOSING repository, so a fixture
     * planted under the system temp directory could silently be judged as if it
     * were some other checkout.
     *
     * @return list<string>|null
     */
    private static function trackedMarkdown(string $root): ?array
    {
        $top = self::git($root, 'rev-parse', '--show-toplevel');

        if ($top === null || str_replace('\\', '/', trim($top)) !== rtrim($root, '/')) {
            return null;
        }

        $listing = self::git($root, 'ls-files', '--cached', '--others', '--exclude-standard', '--', '*.md');

        if ($listing === null) {
            return null;
        }

        $paths = [];

        $lines = preg_split('#\R#', trim($listing));

        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $path = trim($line);

            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Run one git command in a tree and return its output, or null if it failed.
     */
    private static function git(string $root, string ...$arguments): ?string
    {
        $command = ['git', '-C', $root];

        foreach ($arguments as $argument) {
            $command[] = $argument;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0 || !is_string($stdout)) {
            return null;
        }

        return $stdout;
    }

    /**
     * The fallback for a tree git cannot answer for: the planted fixtures.
     *
     * @return list<string>
     */
    private static function walkedMarkdown(string $root): array
    {
        $paths = [];
        $base = $root . '/';

        $pruned = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
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
                    return strtolower($entry->getExtension()) === 'md';
                }

                return !in_array($entry->getFilename(), self::SKIPPED_DIRECTORIES, true);
            },
        );

        foreach (new RecursiveIteratorIterator($pruned) as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            $paths[] = str_replace($base, '', str_replace('\\', '/', $entry->getPathname()));
        }

        sort($paths);

        return $paths;
    }

    /**
     * Install commands inside fenced shell blocks, as [line number, package].
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function installCommands(string $contents): array
    {
        $lines = preg_split('#\R#', $contents);

        if ($lines === false) {
            return [];
        }

        $commands = [];
        $inShellBlock = false;
        $fence = '';

        foreach ($lines as $index => $line) {
            if (preg_match('#^\s*(`{3,}|~{3,})\s*([A-Za-z0-9_+-]*)#', $line, $open) === 1) {
                if ($fence === '') {
                    $fence = $open[1];
                    $inShellBlock = in_array(strtolower($open[2]), self::SHELL_LANGUAGES, true);
                } elseif (str_starts_with(trim($line), $fence)) {
                    $fence = '';
                    $inShellBlock = false;
                }

                continue;
            }

            if (!$inShellBlock) {
                continue;
            }

            $matched = preg_match(
                '#^\s*(?:\$\s*)?composer\s+(?:require|create-project)\s+'
                . '([a-z0-9][a-z0-9._-]*)/([a-z0-9][a-z0-9._-]*)#i',
                $line,
                $command,
            );

            if ($matched !== 1) {
                continue;
            }

            $vendor = strtolower($command[1]);

            if (!in_array($vendor, self::OWNED_VENDORS, true)) {
                continue;
            }

            $commands[] = [$index + 1, $vendor . '/' . strtolower($command[2])];
        }

        return $commands;
    }
}
