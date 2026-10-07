<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AppConfig;
use Pulsar\Config\AuditConfig;
use Pulsar\Config\AuthConfig;
use Pulsar\Config\AuthorizationConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Config\TwoFactorConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ConfigWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

#[CoversClass(ConfigWiring::class)]
final class ConfigWiringTest extends TestCase
{
    private Container $container;
    private Router $router;
    private MiddlewarePipeline $middleware;
    private MiddlewareRegistry $middlewareRegistry;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->router = new Router();
        $this->middleware = new MiddlewarePipeline($this->container);
        $this->middlewareRegistry = new MiddlewareRegistry();
    }

    #[Test]
    public function wireRegistersConfigManagerAndRepository(): void
    {
        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new ConfigWiring();
        $wiring->wire($this->container, $configManager, $this->middleware, $this->middlewareRegistry, $this->router);

        self::assertTrue($this->container->has(ConfigManager::class));
        self::assertTrue($this->container->has(ConfigManagerInterface::class));
        self::assertTrue($this->container->has(ConfigRepository::class));
        self::assertTrue($this->container->has(Environment::class));
    }

    #[Test]
    public function wireRegistersAppConfig(): void
    {
        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new ConfigWiring();
        $wiring->wire($this->container, $configManager, $this->middleware, $this->middlewareRegistry, $this->router);

        self::assertTrue($this->container->has(AppConfig::class));
        $appConfig = $this->container->get(AppConfig::class);
        self::assertInstanceOf(AppConfig::class, $appConfig);
    }

    #[Test]
    public function wireRegistersObservabilityConfig(): void
    {
        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new ConfigWiring();
        $wiring->wire($this->container, $configManager, $this->middleware, $this->middlewareRegistry, $this->router);

        self::assertTrue($this->container->has(ObservabilityConfig::class));
        self::assertTrue($this->container->has(AuditConfig::class));
    }

    #[Test]
    public function wireRegistersSecuritySubConfigs(): void
    {
        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new ConfigWiring();
        $wiring->wire($this->container, $configManager, $this->middleware, $this->middlewareRegistry, $this->router);

        self::assertTrue($this->container->has(SecurityConfig::class));
        self::assertTrue($this->container->has(SessionConfig::class));
        self::assertTrue($this->container->has(CsrfConfig::class));
        self::assertTrue($this->container->has(SecurityHeadersConfig::class));
    }

    #[Test]
    public function wireRegistersAuthConfigWhenPresent(): void
    {
        $configManager = $this->createConfigManagerWithAuth();
        $configManager->load();

        $wiring = new ConfigWiring();
        $wiring->wire($this->container, $configManager, $this->middleware, $this->middlewareRegistry, $this->router);

        self::assertTrue($this->container->has(AuthConfig::class));
        self::assertTrue($this->container->has(TwoFactorConfig::class));
        self::assertTrue($this->container->has(AuthorizationConfig::class));
    }

    #[Test]
    public function wireSkipsAuthConfigWhenNull(): void
    {
        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new ConfigWiring();
        $wiring->wire($this->container, $configManager, $this->middleware, $this->middlewareRegistry, $this->router);

        self::assertFalse($this->container->has(AuthConfig::class));
    }

    private function createConfigManager(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_wiring_test_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => true, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []], "metrics" => ["enabled" => false], "tracing" => ["enabled" => false]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithAuth(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_wiring_auth_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => true, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => [], "auth" => ["guards" => [], "two_factor" => [], "authorization" => []]];');

        return new ConfigManager($configPath);
    }
}
