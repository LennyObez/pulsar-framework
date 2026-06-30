<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Core\Wiring\Contract\WiringContractInspector;
use Pulsar\Core\Wiring\WiringList;
use Pulsar\Filesystem\SafeFilesystem;
use Pulsar\Filesystem\SafePath;

use function file_put_contents;
use function getcwd;
use function in_array;
use function is_dir;
use function ltrim;
use function mkdir;
use function str_starts_with;
use function strlen;
use function substr;
use function uniqid;

/**
 * Boots the full kernel and verifies the contract of every wiring that
 * describes one — the regression guard for the silent-unbound-optional bug
 * class (CacheWiring failing to bind TaggedCacheInterface left three anti-spam
 * features inert in every app, caught by nothing). CI fails here if any shipped
 * wiring leaves a feature degraded by a binding that another shipped wiring
 * declares it provides, or hard-requires a binding the graph never provides.
 */
#[CoversClass(WiringList::class)]
#[CoversClass(WiringContractInspector::class)]
final class WiringContractGraphTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->tempDir = $cwd . '/var/tmp_pulsar_wiring_contract_' . uniqid();
        mkdir($this->tempDir, 0o775, true);

        file_put_contents($this->tempDir . '/app.php', "<?php return ['name' => 'TestApp', 'env' => 'local', 'debug' => false, 'timezone' => 'UTC', 'locale' => 'en'];");
        file_put_contents($this->tempDir . '/observability.php', '<?php return ["logging" => ["default_channel" => "null", "level" => "debug", "channels" => ["null" => ["driver" => "stream", "stream" => "php://memory"]]]];');
        file_put_contents($this->tempDir . '/security.php', '<?php return ["session" => [], "csrf" => ["enabled" => false], "headers" => [], "rate_limiting" => ["enabled" => false]];');
        // Cache ENABLED so CacheWiring actually binds TaggedCacheInterface — the
        // binding anti-spam's features depend on. With it bound, anti-spam must
        // report no degradation; if the binding regressed, this test fails.
        file_put_contents($this->tempDir . '/cache.php', '<?php return ["enabled" => true];');
    }

    protected function tearDown(): void
    {
        $cwd = getcwd();
        if ($cwd === false || !is_dir($this->tempDir)) {
            return;
        }

        $relative = str_starts_with($this->tempDir, $cwd)
            ? ltrim(substr($this->tempDir, strlen($cwd)), '/\\')
            : $this->tempDir;
        $safe = SafePath::resolveUnderCwd($relative);

        if ($safe !== null) {
            new SafeFilesystem()->removeDirectoryRecursive($safe);
        }
    }

    /**
     * @return list<WiringContract>
     */
    private function describedContracts(): array
    {
        $contracts = [];

        foreach (WiringList::default() as $wiring) {
            if ($wiring instanceof DescribesWiring) {
                $contracts[] = $wiring->describeWiring();
            }
        }

        return $contracts;
    }

    #[Test]
    public function noWiringLeavesAFeatureDegradedByABindingTheGraphProvides(): void
    {
        $kernel = new Kernel(configManager: new ConfigManager(configPath: $this->tempDir));
        $kernel->boot();

        $contracts = $this->describedContracts();
        self::assertNotEmpty($contracts, 'Expected at least the cache and anti-spam wirings to describe contracts');

        // Union of every binding the graph claims to provide.
        $provided = [];
        foreach ($contracts as $contract) {
            foreach ($contract->provides as $binding) {
                $provided[$binding] = true;
            }
        }

        $inspector = new WiringContractInspector($kernel->container());

        // A feature degraded because a binding ANOTHER wiring declares it
        // provides is missing = an intra-framework gap (the TaggedCache bug).
        $gaps = [];
        foreach ($inspector->degradedFeatures($contracts) as $degraded) {
            if (isset($provided[$degraded->missingBinding])) {
                $gaps[] = $degraded->describe();
            }
        }

        self::assertSame([], $gaps, "Feature(s) inert despite a provider in the graph:\n" . implode("\n", $gaps));
    }

    #[Test]
    public function everyRequiredBindingIsProvidedOrResolvableAfterBoot(): void
    {
        $kernel = new Kernel(configManager: new ConfigManager(configPath: $this->tempDir));
        $kernel->boot();

        $inspector = new WiringContractInspector($kernel->container());
        $unsatisfied = $inspector->unsatisfiedRequirements($this->describedContracts());

        self::assertSame([], $unsatisfied, 'A wiring requires a binding the graph never provides');
    }

    #[Test]
    public function taggedCacheIsBoundAndAntiSpamHasNoDegradationWhenCacheEnabled(): void
    {
        $kernel = new Kernel(configManager: new ConfigManager(configPath: $this->tempDir));
        $kernel->boot();

        // Direct regression assertion: the binding that silently went missing.
        self::assertTrue(
            $kernel->container()->has(\Pulsar\Cache\Application\TaggedCacheInterface::class),
            'CacheWiring must bind TaggedCacheInterface when cache is enabled',
        );

        $degradedSecurity = [];
        foreach (new WiringContractInspector($kernel->container())->degradedFeatures($this->describedContracts()) as $d) {
            if ($d->security && in_array($d->missingBinding, [\Pulsar\Cache\Application\TaggedCacheInterface::class], true)) {
                $degradedSecurity[] = $d->describe();
            }
        }

        self::assertSame([], $degradedSecurity, 'Anti-spam single-use replay protection must be active when cache is enabled');
    }
}
