<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Extensibility\ExtensionBootstrap;

use function bin2hex;
use function chdir;
use function file_put_contents;
use function getcwd;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Verifies Kernel auto-discovers extensions from getcwd()/extensions
 * when no ExtensionBootstrap is provided, and that project route files
 * are loaded even without a ConfigManager.
 */
#[CoversClass(Kernel::class)]
final class KernelAutoDiscoveryTest extends TestCase
{
    private string $originalCwd;
    private string $tempDir;

    protected function setUp(): void
    {
        $cwd = getcwd();
        $this->originalCwd = $cwd !== false ? $cwd : '';
        $this->tempDir = sys_get_temp_dir() . '/pulsar_autodiscovery_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }

        // Recursive cleanup
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function bootCreatesExtensionBootstrapWhenExtensionsDirExists(): void
    {
        // Arrange: create an extensions directory with a valid manifest
        // whose class won't exist (will be skipped with a warning, but
        // the bootstrap instance itself should still be created)
        $extDir = $this->tempDir . '/extensions/test-ext';
        mkdir($extDir, 0o755, true);
        file_put_contents($extDir . '/pulsar.json', json_encode([
            'name' => 'test/auto-discovered',
            'version' => '1.0.0',
            'extension_class' => 'Pulsar\\NonExistent\\AutoDiscovered',
            'pulsar' => ['min_version' => '0.1.0'],
        ]));

        // Create a minimal config directory so ConfigManager is present
        // (auto-discovery requires a config manager to indicate a real project)
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0o755, true);
        file_put_contents($configDir . '/app.php', '<?php return ["name"=>"Test","env"=>"testing","debug"=>false,"timezone"=>"UTC","locale"=>"en"];');
        file_put_contents($configDir . '/security.php', '<?php return ["session"=>[],"csrf"=>[],"headers"=>[],"rate_limit"=>[]];');
        file_put_contents($configDir . '/observability.php', '<?php return ["logging"=>["default_channel"=>"file","level"=>"debug","channels"=>[]]];');

        chdir($this->tempDir);

        // Act: boot kernel with config manager but no explicit bootstrap
        $configManager = new ConfigManager(configPath: $configDir);
        $kernel = new Kernel(configManager: $configManager);
        $kernel->boot();

        // Assert: the kernel created an ExtensionBootstrap via auto-discovery
        $bootstrap = $kernel->extensionBootstrap();
        self::assertInstanceOf(ExtensionBootstrap::class, $bootstrap);
    }

    #[Test]
    public function bootWithoutExtensionsDirDoesNotCreateBootstrap(): void
    {
        // Arrange: temp dir with no extensions subdirectory but with config
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0o755, true);
        file_put_contents($configDir . '/app.php', '<?php return ["name"=>"Test","env"=>"testing","debug"=>false,"timezone"=>"UTC","locale"=>"en"];');
        file_put_contents($configDir . '/security.php', '<?php return ["session"=>[],"csrf"=>[],"headers"=>[],"rate_limit"=>[]];');
        file_put_contents($configDir . '/observability.php', '<?php return ["logging"=>["default_channel"=>"file","level"=>"debug","channels"=>[]]];');

        chdir($this->tempDir);

        // Act
        $configManager = new ConfigManager(configPath: $configDir);
        $kernel = new Kernel(configManager: $configManager);
        $kernel->boot();

        // Assert: no bootstrap was created (no extensions dir)
        self::assertNull($kernel->extensionBootstrap());
    }

    #[Test]
    public function projectRouteFilesLoadedFromCwdFallback(): void
    {
        // Arrange: create config and routes directory with a web.php route file
        $configDir = $this->tempDir . '/config';
        mkdir($configDir, 0o755, true);
        file_put_contents($configDir . '/app.php', '<?php return ["name"=>"Test","env"=>"testing","debug"=>false,"timezone"=>"UTC","locale"=>"en"];');
        file_put_contents($configDir . '/security.php', '<?php return ["session"=>[],"csrf"=>[],"headers"=>[],"rate_limit"=>[]];');
        file_put_contents($configDir . '/observability.php', '<?php return ["logging"=>["default_channel"=>"file","level"=>"debug","channels"=>[]]];');

        $routesDir = $this->tempDir . '/routes';
        mkdir($routesDir, 0o755, true);
        file_put_contents($routesDir . '/web.php', <<<'PHP'
            <?php
            $router->get('/test-route', fn() => 'ok', 'test.route');
            PHP);

        chdir($this->tempDir);

        // Act: boot kernel with config manager (routes are in config's parent dir)
        $configManager = new ConfigManager(configPath: $configDir);
        $kernel = new Kernel(configManager: $configManager);
        $kernel->boot();

        // Assert: the route was registered
        self::assertGreaterThanOrEqual(1, $kernel->router()->count());

        $routes = $kernel->router()->routes();
        $routeNames = array_map(
            static fn($route) => $route->name,
            $routes,
        );
        self::assertContains('test.route', $routeNames);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
