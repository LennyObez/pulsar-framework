<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Boot\ExtensionDiscovery;

use function array_filter;
use function bin2hex;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ExtensionDiscovery::class)]
final class ExtensionDiscoveryTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_extdisc_' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/config', 0o750, true);
        mkdir($this->projectRoot . '/extensions/keep', 0o750, true);
        mkdir($this->projectRoot . '/extensions/drop', 0o750, true);

        $this->writeManifest('keep', 'test/keep');
        $this->writeManifest('drop', 'test/drop');
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot . '/config/app.php');
        @unlink($this->projectRoot . '/extensions/keep/pulsar.json');
        @unlink($this->projectRoot . '/extensions/drop/pulsar.json');
        @rmdir($this->projectRoot . '/extensions/keep');
        @rmdir($this->projectRoot . '/extensions/drop');
        @rmdir($this->projectRoot . '/extensions');
        @rmdir($this->projectRoot . '/config');
        @rmdir($this->projectRoot);
    }

    #[Test]
    public function autoDiscoveryHonorsTheEnabledFilterFromAppConfig(): void
    {
        $this->writeAppConfig("<?php return ['extensions' => ['enabled' => ['test/keep']]];");

        $bootstrap = ExtensionDiscovery::discover($this->configManager());

        self::assertNotNull($bootstrap);
        // The excluded extension is reported as disabled-by-config, proving the
        // filter was applied on the auto-discovery path (not just the scaffold).
        $disabled = array_filter(
            $bootstrap->getLoadWarnings(),
            static fn(string $w): bool => str_contains($w, 'disabled by config') && str_contains($w, 'test/drop'),
        );
        self::assertCount(1, $disabled);
    }

    #[Test]
    public function autoDiscoveryLoadsEverythingWhenNoEnabledKeyIsPresent(): void
    {
        $this->writeAppConfig("<?php return ['name' => 'demo'];");

        $bootstrap = ExtensionDiscovery::discover($this->configManager());

        self::assertNotNull($bootstrap);
        $disabled = array_filter(
            $bootstrap->getLoadWarnings(),
            static fn(string $w): bool => str_contains($w, 'disabled by config'),
        );
        self::assertCount(0, $disabled, 'no extension should be reported disabled when extensions.enabled is absent');
    }

    #[Test]
    public function discoverReturnsNullWhenNoExtensionsDirectoryExists(): void
    {
        @unlink($this->projectRoot . '/extensions/keep/pulsar.json');
        @unlink($this->projectRoot . '/extensions/drop/pulsar.json');
        @rmdir($this->projectRoot . '/extensions/keep');
        @rmdir($this->projectRoot . '/extensions/drop');
        @rmdir($this->projectRoot . '/extensions');

        self::assertNull(ExtensionDiscovery::discover($this->configManager()));
    }

    private function configManager(): ConfigManager
    {
        return new ConfigManager(configPath: $this->projectRoot . '/config');
    }

    private function writeAppConfig(string $php): void
    {
        file_put_contents($this->projectRoot . '/config/app.php', $php . "\n");
    }

    private function writeManifest(string $dir, string $name): void
    {
        file_put_contents(
            $this->projectRoot . '/extensions/' . $dir . '/pulsar.json',
            (string) json_encode([
                'name' => $name,
                'version' => '1.0.0',
                'extension_class' => 'Pulsar\\NonExistent\\' . $dir . 'Extension',
                'pulsar' => ['min_version' => '0.1.0'],
            ]),
        );
    }
}
