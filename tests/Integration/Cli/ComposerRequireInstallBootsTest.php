<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Cli;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function dirname;
use function fclose;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_string;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function realpath;
use function rmdir;
use function scandir;
use function str_replace;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;
use function var_export;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

/**
 * `composer require pulsar/framework` into an empty project yields a working CLI.
 *
 * ## Why this is not the same test as the clone shape
 *
 * {@see ScaffoldedProjectBootsTest::initSucceedsFromAnEmptyDirectory()} runs the
 * framework's own `bin/pulsar` from an empty directory. That is the CLONE shape, and
 * `bin/pulsar` resolves three things differently in it:
 *
 *   - **which autoloader is loaded.** The search is `getcwd()/vendor/autoload.php`
 *     first, `__DIR__/../vendor/autoload.php` second. A clone has no project vendor
 *     directory and falls through to the framework's own; a `composer require` project
 *     has one and takes the first branch. The sentinel below is what proves this test
 *     is on that branch rather than the other.
 *   - **where the framework root sits.** In a clone it is the checkout; under
 *     `composer require` it is `vendor/pulsar/framework`, INSIDE the project — so
 *     `config/` and `extensions/` the framework ships are reachable from the project
 *     root without belonging to the project.
 *   - **how the binary is reached.** Composer installs `bin/pulsar` as a proxy under
 *     `vendor/bin/`, which is what `docs/install.md` tells the reader to run.
 *
 * ## The failure this holds shut
 *
 * `bin/pulsar` declines to fall back to the framework's own `config/` outside a
 * framework checkout, but used to load the framework's bundled `extensions/`
 * regardless. With no `config/extensions.php` to read trust tiers from, ExtensionSandbox
 * fails closed, caps every bundled extension at Community, and boot stops at the first
 * one needing a core capability. Measured against the unfixed binary, in exactly the
 * layout below: `list`, `status` and `init` each exited 1 on
 * `Kernel boot failed: Failed to boot extension "pulsar/mcp-server": Cannot resolve
 * service "Pulsar\Config\ConfigManagerInterface": Community tier does not have
 * ConfigWrite capability`. `init` is step 2 of the install guide and the directory it
 * runs in is empty by definition, so there was no first command that worked.
 *
 * ## What the layout below does and does not reproduce
 *
 * It reproduces every input `bin/pulsar` branches on: a project root with no `config/`,
 * a project `vendor/autoload.php` that the autoloader search finds first, a
 * Composer-shaped proxy under `vendor/bin/`, and a framework root that is not the
 * project root while still carrying `config/` and `extensions/`. Composer's own proxy
 * `include`s the real binary, so `__DIR__` inside it is the framework's `bin/`
 * directory either way, and every path the script derives from `__DIR__` is the same.
 *
 * It does not reinstall the framework's dependencies into the workspace: which packages
 * Composer resolves is a packaging question, and answering it here would mean a network
 * install inside a unit run.
 */
#[CoversNothing]
final class ComposerRequireInstallBootsTest extends TestCase
{
    /**
     * Written by the project's autoloader, so the assertions can tell which autoloader
     * `bin/pulsar` actually loaded instead of assuming.
     */
    private const string SENTINEL = 'project-autoloader-was-used';

    private string $workspace;

    protected function setUp(): void
    {
        $temp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $this->workspace = $temp . DIRECTORY_SEPARATOR . 'pulsar_composer_require_' . bin2hex(random_bytes(8));

        mkdir($this->workspace . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin', 0o750, true);

        $this->writeProjectAutoloader();
        $this->writeBinProxy();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    /**
     * The first command a reader runs after `composer require` is not `init` — it is
     * whatever they type to see that the install worked.
     */
    #[Test]
    public function commandsRunFromAProjectThatHasNoConfigDirectory(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runPulsar('list');

        self::assertSame(
            0,
            $exitCode,
            "`vendor/bin/pulsar list` failed in a project with no config/.\n"
            . "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
        );
        self::assertStringNotContainsString('Kernel boot failed', $stderr);
        self::assertStringContainsString('Available commands', $stdout);

        self::assertFileExists(
            $this->workspace . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . self::SENTINEL,
            'the framework loaded its own autoloader rather than the project\'s, so this ran the '
            . 'clone shape and proves nothing about a composer-require install',
        );
    }

    /**
     * `php vendor/bin/pulsar init my-project` is step 2 of `docs/install.md`, and the
     * directory it runs in has no `config/` because the project does not exist yet.
     */
    #[Test]
    public function initScaffoldsAProjectFromAComposerRequireInstall(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runPulsar('init', 'my-project');

        self::assertSame(
            0,
            $exitCode,
            "`vendor/bin/pulsar init` failed in a project with no config/.\n"
            . "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
        );
        self::assertStringNotContainsString('Kernel boot failed', $stderr);
        self::assertFileExists(
            $this->workspace . DIRECTORY_SEPARATOR . 'my-project' . DIRECTORY_SEPARATOR
            . 'public' . DIRECTORY_SEPARATOR . 'index.php',
            'init reported success but wrote no entry point',
        );
    }

    /**
     * The scaffolded project is where the CLI has to keep working, and it is the first
     * moment `config/` exists — so it is the first moment the bundled extensions load
     * for real.
     */
    #[Test]
    public function theScaffoldedProjectStillRunsCommandsOnceItHasAConfigDirectory(): void
    {
        [$initExit, $initOut, $initErr] = $this->runPulsar('init', 'my-project');
        self::assertSame(0, $initExit, "init failed.\nSTDOUT:\n{$initOut}\nSTDERR:\n{$initErr}");

        [$exitCode, $stdout, $stderr] = $this->runPulsarIn(
            $this->workspace . DIRECTORY_SEPARATOR . 'my-project',
            'status',
        );

        self::assertSame(
            0,
            $exitCode,
            "`status` failed inside the project init had just scaffolded.\n"
            . "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
        );
        self::assertStringNotContainsString('Kernel boot failed', $stderr);
    }

    /**
     * The autoloader Composer would write into the project.
     *
     * It delegates to this checkout's own so the test does not perform an install, and
     * it records that it ran — which is the whole difference between this shape and the
     * clone shape, and therefore worth asserting rather than assuming.
     */
    private function writeProjectAutoloader(): void
    {
        $frameworkAutoloader = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'autoload.php';

        $sentinel = $this->workspace . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . self::SENTINEL;

        $source = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// Stands in for the autoloader Composer generates in a project that ran\n"
            . "// `composer require pulsar/framework`.\n"
            . 'file_put_contents(' . var_export($sentinel, true) . ", '');\n\n"
            . 'return require ' . var_export($frameworkAutoloader, true) . ";\n";

        file_put_contents(
            $this->workspace . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
            $source,
        );
    }

    /**
     * The proxy Composer installs under `vendor/bin/` for a package declaring
     * `"bin": ["bin/pulsar"]`.
     *
     * Composer's own proxy reaches the binary as
     * `__DIR__ . '/../pulsar/framework/bin/pulsar'`; this one names the checkout
     * directly. `__DIR__` inside the included binary is the framework's `bin/`
     * directory in both cases, which is what every path `bin/pulsar` derives depends on.
     */
    private function writeBinProxy(): void
    {
        $binary = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pulsar';

        self::assertFileExists($binary, 'bin/pulsar must exist to be proxied');

        $source = "#!/usr/bin/env php\n<?php\n\n"
            . "\$GLOBALS['_composer_bin_dir'] = __DIR__;\n"
            . "\$GLOBALS['_composer_autoload_path'] = __DIR__ . '/..' . '/autoload.php';\n\n"
            . 'return include ' . var_export($binary, true) . ";\n";

        file_put_contents($this->proxy(), $source);
    }

    private function proxy(): string
    {
        return $this->workspace . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pulsar';
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function runPulsar(string ...$arguments): array
    {
        return $this->runPulsarIn($this->workspace, ...$arguments);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function runPulsarIn(string $cwd, string ...$arguments): array
    {
        // Built by appending rather than by spreading: a variadic can carry string keys,
        // so the spread is not a list, and proc_open takes a list.
        $command = [PHP_BINARY, $this->proxy()];

        foreach ($arguments as $argument) {
            $command[] = $argument;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd);

        self::assertIsResource($process, 'Failed to start: ' . implode(' ', $command));

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            proc_close($process),
            is_string($stdout) ? str_replace("\r\n", "\n", $stdout) : '',
            is_string($stderr) ? str_replace("\r\n", "\n", $stderr) : '',
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($child)) {
                $this->removeDirectory($child);

                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
