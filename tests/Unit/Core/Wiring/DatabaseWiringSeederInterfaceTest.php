<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\DatabaseWiring;
use Pulsar\Database\Seeder\SeederRunner;
use Pulsar\Database\Seeder\SeederRunnerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * Verifies that DatabaseWiring binds both the concrete SeederRunner
 * and the SeederRunnerInterface so callers can resolve via either.
 */
#[CoversClass(DatabaseWiring::class)]
final class DatabaseWiringSeederInterfaceTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . '/pulsar_seeder_iface_' . bin2hex(random_bytes(4));
        @mkdir($this->configPath, 0o755, true);

        file_put_contents(
            $this->configPath . '/app.php',
            '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];',
        );
        file_put_contents(
            $this->configPath . '/observability.php',
            '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];',
        );
        file_put_contents(
            $this->configPath . '/security.php',
            '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];',
        );
        file_put_contents(
            $this->configPath . '/database.php',
            '<?php return ["default" => "sqlite", "connections" => ["sqlite" => ["driver" => "sqlite", "database" => ":memory:"]]];',
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath . '/app.php');
        @unlink($this->configPath . '/observability.php');
        @unlink($this->configPath . '/security.php');
        @unlink($this->configPath . '/database.php');
        @rmdir($this->configPath);
    }

    #[Test]
    public function seederRunnerInterfaceIsBoundAfterWiring(): void
    {
        // Arrange
        $container = new Container();
        $configManager = new ConfigManager($this->configPath);
        $configManager->load();

        $wiring = new DatabaseWiring();
        $wiring->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        // Assert
        self::assertTrue(
            $container->has(SeederRunnerInterface::class),
            'SeederRunnerInterface must be registered after DatabaseWiring',
        );
    }

    #[Test]
    public function seederRunnerInterfaceResolvesToSeederRunner(): void
    {
        // Arrange
        $container = new Container();
        $configManager = new ConfigManager($this->configPath);
        $configManager->load();

        $wiring = new DatabaseWiring();
        $wiring->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        // Act
        $instance = $container->get(SeederRunnerInterface::class);

        // Assert
        self::assertInstanceOf(SeederRunner::class, $instance);
    }

    #[Test]
    public function bothBindingsResolveToSameType(): void
    {
        // Arrange
        $container = new Container();
        $configManager = new ConfigManager($this->configPath);
        $configManager->load();

        $wiring = new DatabaseWiring();
        $wiring->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        // Act
        $concrete = $container->get(SeederRunner::class);
        $interface = $container->get(SeederRunnerInterface::class);

        // Assert: both resolve to SeederRunner instances
        self::assertInstanceOf(SeederRunner::class, $concrete);
        self::assertInstanceOf(SeederRunner::class, $interface);
    }
}
