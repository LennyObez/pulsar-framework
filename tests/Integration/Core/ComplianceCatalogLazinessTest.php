<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Core;

use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Core\Wiring\ComplianceCatalogWiring;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

use function bin2hex;
use function dirname;
use function file_put_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * The compliance control catalog must cost a boot nothing.
 *
 * A comment saying "this is lazy" is not enforcement, and neither is a test that
 * runs in the shared PHPUnit process: by the time this file executes, dozens of
 * other tests have already autoloaded every compliance mapping there is, so
 * `class_exists(..., autoload: false)` would answer true no matter what the
 * wiring did. The only place the question has a meaningful answer is a process
 * that has done nothing else, so the check runs in one.
 *
 * What it asserts, in a fresh interpreter:
 *
 *  1. after {@see ComplianceCatalogWiring::wire()}, not one of the sixteen
 *     framework mapping classes is loaded, no {@see \Pulsar\Compliance\Control\ControlDeclaration}
 *     exists, and even {@see ControlCatalog} itself has not been autoloaded —
 *     the binding is a closure and `has()` is answered from the binding;
 *  2. resolving the catalog out of the container still loads no mapping: what
 *     comes back holds a deferred source, not declarations;
 *  3. the first READ builds all 215 declarations and loads the mappings then.
 *
 * Boot pays for step 1. Only `compliance:report` and the `compliance:check` gate
 * reach step 3.
 */
#[CoversClass(ComplianceCatalogWiring::class)]
#[CoversClass(ControlCatalog::class)]
final class ComplianceCatalogLazinessTest extends TestCase
{
    use InvokesCiScript;

    /** A mapping that is certain to be in the catalog, named as a string so the test itself never loads it. */
    private const string A_MAPPING = 'Pulsar\Compliance\Frameworks\PciDssMapping';

    private const string A_DECLARATION = 'Pulsar\Compliance\Control\ControlDeclaration';

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_dir($path)) {
                @rmdir($path);

                continue;
            }

            @unlink($path);
        }

        $this->temporary = [];

        parent::tearDown();
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function wiringTheCatalogLoadsNoMappingAndBuildsNoDeclaration(): void
    {
        $observed = $this->observeAFreshProcess();

        self::assertTrue($observed['bound'], 'The wiring did not bind a catalog at all.');

        self::assertFalse(
            $observed['mappingLoadedAfterWire'],
            self::A_MAPPING . ' was autoloaded during boot. The catalog is being built at wiring time again: '
                . 'that is 0.52 ms warm and ~22 ms cold on every request of every application, for 215 '
                . 'declarations no request reads.',
        );
        self::assertFalse(
            $observed['declarationLoadedAfterWire'],
            'A ControlDeclaration was constructed during boot.',
        );
        self::assertFalse(
            $observed['catalogLoadedAfterWire'],
            'ControlCatalog itself was autoloaded during boot; the binding must stay a closure so that '
                . 'has() — all the console asks before registering the report command — resolves nothing.',
        );
    }

    /**
     * Resolving is not reading. The console resolves the catalog to hand it to the
     * report command; the command reads it when it runs, and not before.
     *
     * @throws JsonException
     */
    #[Test]
    public function resolvingTheCatalogStillBuildsNothingAndTheFirstReadBuildsEverything(): void
    {
        $observed = $this->observeAFreshProcess();

        self::assertFalse(
            $observed['mappingLoadedAfterResolve'],
            'Resolving the catalog out of the container executed its deferred sources.',
        );

        self::assertTrue(
            $observed['mappingLoadedAfterRead'],
            'Reading the catalog did not load the mappings, so it cannot have built anything.',
        );
        self::assertSame(
            215,
            $observed['count'],
            'The deferred build produced a different number of controls than registering them eagerly did.',
        );
    }

    /**
     * Runs the probe below in an interpreter that has loaded nothing else.
     *
     * @return array{bound: bool, mappingLoadedAfterWire: bool, declarationLoadedAfterWire: bool,
     *     catalogLoadedAfterWire: bool, mappingLoadedAfterResolve: bool, mappingLoadedAfterRead: bool, count: int}
     *
     * @throws JsonException
     */
    private function observeAFreshProcess(): array
    {
        $root = dirname(__DIR__, 3);
        $script = $this->write($this->probe($root, $this->configDirectory()));

        [$status, $stdout, $stderr] = $this->runScript($script);

        self::assertSame(0, $status, 'The probe process failed: ' . $stdout . $stderr);

        /** @var mixed $decoded */
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('count', $decoded);

        /**
         * @var array{bound: bool, mappingLoadedAfterWire: bool, declarationLoadedAfterWire: bool,
         *     catalogLoadedAfterWire: bool, mappingLoadedAfterResolve: bool, mappingLoadedAfterRead: bool,
         *     count: int} $decoded
         */
        return $decoded;
    }

    /**
     * The probe. Everything it asks about is named as a STRING: writing
     * `PciDssMapping::class` would be harmless, but a use-statement or a typed
     * signature would not be, and a probe that loads the class it is testing for
     * answers its own question.
     */
    private function probe(string $root, string $config): string
    {
        $root = str_replace('\\', '/', $root);
        $config = str_replace('\\', '/', $config);

        return <<<PHP
            <?php

            declare(strict_types=1);

            require '{$root}/vendor/autoload.php';

            \$container = new Pulsar\\Container\\Container();
            \$configManager = new Pulsar\\Config\\ConfigManager('{$config}');
            \$configManager->load();

            new Pulsar\\Core\\Wiring\\ComplianceCatalogWiring()->wire(
                \$container,
                \$configManager,
                new Pulsar\\Http\\Middleware\\MiddlewarePipeline(\$container),
                new Pulsar\\Http\\Middleware\\MiddlewareRegistry(),
                new Pulsar\\Routing\\Router(),
            );

            \$catalogId = 'Pulsar\\\\Compliance\\\\ControlCatalog';
            \$mapping = '{$this->escaped(self::A_MAPPING)}';
            \$declaration = '{$this->escaped(self::A_DECLARATION)}';

            \$observed = [
                'bound' => \$container->has(\$catalogId),
                'mappingLoadedAfterWire' => class_exists(\$mapping, false),
                'declarationLoadedAfterWire' => class_exists(\$declaration, false),
                'catalogLoadedAfterWire' => class_exists(\$catalogId, false),
            ];

            \$catalog = \$container->get(\$catalogId);
            \$observed['mappingLoadedAfterResolve'] = class_exists(\$mapping, false);

            \$observed['count'] = \$catalog->count();
            \$observed['mappingLoadedAfterRead'] = class_exists(\$mapping, false);

            echo json_encode(\$observed, JSON_THROW_ON_ERROR);
            PHP;
    }

    private function escaped(string $class): string
    {
        return str_replace('\\', '\\\\', $class);
    }

    private function write(string $contents): string
    {
        $path = sys_get_temp_dir() . '/pulsar_catalog_laziness_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, $contents);
        $this->temporary[] = $path;

        return $path;
    }

    /**
     * The three config files ConfigManager requires, and nothing else: the wiring
     * under test reads none of them, and a probe that needed the project's own
     * config would be measuring the project rather than the wiring.
     */
    private function configDirectory(): string
    {
        $path = sys_get_temp_dir() . '/pulsar_catalog_laziness_config_' . bin2hex(random_bytes(6));
        @mkdir($path, 0o755, true);

        file_put_contents(
            $path . '/app.php',
            '<?php return ["name" => "Probe", "env" => "testing", "debug" => false, '
                . '"timezone" => "UTC", "locale" => "en"];',
        );
        file_put_contents(
            $path . '/observability.php',
            '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];',
        );
        file_put_contents(
            $path . '/security.php',
            '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];',
        );

        $this->temporary[] = $path . '/app.php';
        $this->temporary[] = $path . '/observability.php';
        $this->temporary[] = $path . '/security.php';
        $this->temporary[] = $path;

        return $path;
    }
}
