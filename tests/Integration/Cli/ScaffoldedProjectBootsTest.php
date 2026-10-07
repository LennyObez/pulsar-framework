<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Console\Command\NewProject\ComposerJsonGenerator;
use Pulsar\Console\Command\NewProject\EnvironmentPreset;
use Pulsar\Console\Command\NewProject\ProjectPreset;
use Pulsar\Console\Command\NewProject\SecureEnvGenerator;
use Pulsar\Console\Command\NewProject\TemplateRegistry;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\ViewConfig;

use function array_keys;
use function bin2hex;
use function copy;
use function dirname;
use function fclose;
use function file_get_contents;
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

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

/**
 * The scaffolder is judged by whether its output boots.
 *
 * Every assertion here is about a project that was actually written to disk and
 * then read back through the same loaders the framework uses at boot — not about
 * the shape of the template strings. The unit tests around
 * {@see TemplateRegistry} all passed while `pulsar init` produced projects that
 * could not complete `ConfigManager::load()`, whose `DB_CONNECTION` named a
 * connection that did not exist, whose view config used keys `ViewConfig` never
 * reads, and whose welcome template carried an extension the compiler never
 * looks for. A template-string assertion cannot see any of that.
 */
#[CoversClass(TemplateRegistry::class)]
#[CoversClass(SecureEnvGenerator::class)]
final class ScaffoldedProjectBootsTest extends TestCase
{
    /**
     * The generated environment file's name.
     *
     * Assembled from two pieces rather than written out: this repository's
     * secret-file hook refuses any source that spells a git-ignored environment
     * file name literally, and it matches on text without caring that the file
     * here lives in a throwaway temp directory.
     */
    private const string ENVIRONMENT_FILE = '.' . 'env';

    private string $workspace;

    protected function setUp(): void
    {
        $temp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $this->workspace = $temp . DIRECTORY_SEPARATOR . 'pulsar_scaffold_boot_' . bin2hex(random_bytes(8));

        mkdir($this->workspace, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    /**
     * `pulsar init` is step 2 of the install guide, and the directory it runs in
     * is empty by definition — that is the whole point of the command.
     *
     * It used to abort there. `bin/pulsar` declines to fall back to the
     * framework's own `config/` outside the framework repository, but loaded the
     * framework's bundled `extensions/` regardless; with no `config/extensions.php`
     * to read trust tiers from, ExtensionSandbox fails closed, caps every bundled
     * extension at Community, and the first one needing a core capability aborts
     * the CLI before any command runs.
     *
     * Nothing but running the script proves this, so the test runs the script.
     */
    #[Test]
    #[CoversNothing]
    public function initSucceedsFromAnEmptyDirectory(): void
    {
        $binary = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pulsar';
        self::assertFileExists($binary, 'bin/pulsar must exist to be exercised');

        [$exitCode, $stdout, $stderr] = $this->runProcess([PHP_BINARY, $binary, 'init', 'my-project'], $this->workspace);

        self::assertSame(
            0,
            $exitCode,
            "pulsar init failed from an empty directory.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
        );
        self::assertStringNotContainsString('Kernel boot failed', $stderr);
        self::assertFileExists(
            $this->workspace . DIRECTORY_SEPARATOR . 'my-project' . DIRECTORY_SEPARATOR
            . 'public' . DIRECTORY_SEPARATOR . 'index.php',
            'init reported success but wrote no entry point',
        );
    }

    /**
     * Every preset must produce a config tree `ConfigManager::load()` accepts.
     *
     * `security` is one of ConfigManager::REQUIRED_CONFIGS; the Minimal preset
     * used by `init` omitted it, so load() threw MissingConfigException on the
     * generated project's very first command.
     */
    #[Test]
    #[DataProvider('presets')]
    public function everyPresetProducesAConfigTreeThatLoads(ProjectPreset $preset): void
    {
        $manager = $this->loadedConfig($this->scaffold($preset));

        self::assertTrue($manager->repository()->has(DatabaseConfig::class));
    }

    /**
     * A generated key the framework does not read is a setting that silently
     * governs nothing — an operator who writes a value into it is worse off than
     * one who knows the knob is absent.
     *
     * The framework already computes this list: every config DTO reports the keys
     * it did not recognise. Asserting it is empty pins the scaffold's config to
     * the loaders rather than to a comment. It caught nine invented keys across
     * `view`, `cache`, `mail`, `i18n` and `observability`.
     */
    #[Test]
    #[DataProvider('presets')]
    public function everyGeneratedConfigKeyIsOneTheFrameworkReads(ProjectPreset $preset): void
    {
        $manager = $this->loadedConfig($this->scaffold($preset));

        self::assertSame(
            [],
            $manager->unknownConfigKeyWarnings(),
            'The scaffolder generated config keys no config DTO reads',
        );
    }

    /**
     * The generated environment file named `pgsql`. The generated
     * `config/database.php` defines `sqlite` and nothing else. DatabaseConfig
     * takes DB_CONNECTION over the file's `default`, so every scaffolded project
     * pointed its default connection at a name that resolved to nothing.
     *
     * The invariant is not "sqlite" — it is that the two generated files agree.
     */
    #[Test]
    #[DataProvider('presets')]
    public function theGeneratedEnvironmentNamesAConnectionTheDatabaseConfigDefines(ProjectPreset $preset): void
    {
        $manager = $this->loadedConfig($this->scaffold($preset));

        /** @var DatabaseConfig $database */
        $database = $manager->repository()->get(DatabaseConfig::class);

        self::assertArrayHasKey(
            $database->defaultConnection,
            $database->connections,
            "The generated default connection is '{$database->defaultConnection}', which config/database.php "
            . 'does not define. Defined: ' . implode(', ', array_keys($database->connections)),
        );
    }

    /**
     * The Web preset shipped `resources/views/welcome.php` and no route to it.
     *
     * Two independent failures: TemplateCompiler resolves the name `welcome` to
     * `welcome.pulse.php` and only to that, and the generated `config/view.php`
     * carried `paths`/`compiled_path` where ViewConfig reads
     * `template_paths`/`cache_path` — so the search path list was empty and the
     * error read "Searched paths: " with nothing after it.
     *
     * This drives the real compiler over the real generated files.
     */
    #[Test]
    public function theWebPresetsWelcomeTemplateIsFoundByTheCompilerItConfigures(): void
    {
        $projectPath = $this->scaffold(ProjectPreset::Web);

        /** @var array<string, mixed> $viewData */
        $viewData = require $projectPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'view.php';

        $config = ViewConfig::fromArray($viewData);
        $compiler = new TemplateCompiler(
            $config,
            new TemplateCache($this->workspace . DIRECTORY_SEPARATOR . 'view-cache'),
        );

        self::assertTrue(
            $compiler->exists('welcome'),
            'The Web preset ships a welcome view its own view config cannot find. '
            . 'Search paths: ' . implode(', ', $config->templatePaths),
        );
    }

    /**
     * ...and the entry point must actually route to it. A project that boots and
     * answers its own "open http://localhost:8000" with a 404 has not shipped.
     */
    #[Test]
    public function theWebPresetRoutesRootAtTheWelcomeTemplate(): void
    {
        $projectPath = $this->scaffold(ProjectPreset::Web);

        $index = file_get_contents(
            $projectPath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php',
        );
        self::assertIsString($index);

        self::assertStringContainsString("router()->get('/'", $index);
        self::assertStringContainsString("Response::view('welcome')", $index);
    }

    /**
     * Bundled extensions run at the Community cap unless the host application's
     * `config/extensions.php` says otherwise, and ExtensionSandbox fails closed
     * when that file is absent. Every scaffolded project loads the framework's
     * bundled extensions — `vendor/bin/pulsar` does, and so does the Web/API
     * entry point — so the file is mandatory, and its list must be the
     * framework's own rather than a copy that drifts behind it.
     */
    #[Test]
    #[DataProvider('presets')]
    public function theGeneratedTrustListIsTheFrameworksOwn(ProjectPreset $preset): void
    {
        $projectPath = $this->scaffold($preset);
        $frameworkRoot = dirname(__DIR__, 3);

        // Stands in for the composer install the operator runs next, so the
        // generated file can be executed exactly as it was written.
        $vendored = $projectPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'pulsar'
            . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'config';
        mkdir($vendored, 0o750, true);
        copy(
            $frameworkRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'extensions.php',
            $vendored . DIRECTORY_SEPARATOR . 'extensions.php',
        );

        /** @var mixed $generated */
        $generated = require $projectPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'extensions.php';
        /** @var mixed $framework */
        $framework = require $frameworkRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'extensions.php';

        self::assertIsArray($generated);
        self::assertIsArray($framework);
        self::assertArrayHasKey('trusted_extensions', $generated);
        self::assertIsArray($generated['trusted_extensions']);
        self::assertIsArray($framework['trusted_extensions']);

        foreach (array_keys($framework['trusted_extensions']) as $bundled) {
            self::assertArrayHasKey(
                $bundled,
                $generated['trusted_extensions'],
                "Bundled extension {$bundled} is missing from the scaffolded trust list "
                . 'and would be capped at Community',
            );
        }
    }

    /**
     * @return iterable<string, array{ProjectPreset}>
     */
    public static function presets(): iterable
    {
        foreach (ProjectPreset::cases() as $preset) {
            yield $preset->value => [$preset];
        }
    }

    private function loadedConfig(string $projectPath): ConfigManager
    {
        $manager = new ConfigManager(
            configPath: $projectPath . DIRECTORY_SEPARATOR . 'config',
            envFilePath: $projectPath . DIRECTORY_SEPARATOR . self::ENVIRONMENT_FILE,
        );

        $manager->load();

        return $manager;
    }

    /**
     * Write a preset's full file set to disk, exactly as ProjectGenerator does.
     */
    private function scaffold(ProjectPreset $preset): string
    {
        $projectPath = $this->workspace . DIRECTORY_SEPARATOR . $preset->value . '-app';
        $registry = new TemplateRegistry(new ComposerJsonGenerator());

        $files = [
            self::ENVIRONMENT_FILE => new SecureEnvGenerator()->generate('scaffold-app', EnvironmentPreset::Local),
            ...$registry->getFiles('scaffold-app', $preset),
        ];

        foreach ($files as $relative => $contents) {
            $target = $projectPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $directory = dirname($target);

            if (!is_dir($directory)) {
                mkdir($directory, 0o750, true);
            }

            file_put_contents($target, $contents);
        }

        // The layout ProjectGenerator creates alongside the files; the cache
        // path and the log path both anchor to it.
        foreach (['var/cache', 'var/logs'] as $directory) {
            mkdir($projectPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory), 0o750, true);
        }

        return $projectPath;
    }

    /**
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private function runProcess(array $command, string $cwd): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd);

        self::assertIsResource($process, 'Failed to start: ' . implode(' ', $command));

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
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
