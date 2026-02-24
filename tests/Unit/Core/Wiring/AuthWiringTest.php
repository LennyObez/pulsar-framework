<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\AuthManager;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\Middleware\TwoFactorMiddleware;
use Pulsar\Auth\Password\PasswordHasher;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Auth\TwoFactor\InMemoryRecoveryCodeStore;
use Pulsar\Auth\TwoFactor\InMemoryTotpReplayGuard;
use Pulsar\Auth\TwoFactor\InMemoryTotpSecretStore;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\RecoveryCodeStoreInterface;
use Pulsar\Auth\TwoFactor\RecoveryCodeVerifier;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpReplayGuardInterface;
use Pulsar\Auth\TwoFactor\TotpSecretStoreInterface;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorManager;
use Pulsar\Auth\TwoFactor\TwoFactorManagerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AuthWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Random\Randomizer;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(AuthWiring::class)]
final class AuthWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersAuthServicesWithTwoFactorEnabled(): void
    {
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(authEnabled: true, twoFactorEnabled: true);
        $configManager->load();

        $wiring = new AuthWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(PasswordHasher::class));
        self::assertTrue($container->has(PasswordHasherInterface::class));
        self::assertTrue($container->has(AuthManager::class));
        self::assertTrue($container->has(AuthManagerInterface::class));
        self::assertTrue($container->has(InMemoryRoleRegistry::class));
        self::assertTrue($container->has(RoleRegistryInterface::class));
        self::assertTrue($container->has(Gate::class));
        self::assertTrue($container->has(GateInterface::class));
        self::assertTrue($container->has(AuthenticationMiddleware::class));
        self::assertTrue($container->has(AuthorizationMiddleware::class));
        self::assertTrue($container->has(TwoFactorMiddleware::class));

        // 2FA services
        self::assertTrue($container->has(TotpGenerator::class));
        self::assertTrue($container->has(TotpVerifier::class));
        self::assertTrue($container->has(RecoveryCodeGenerator::class));
        self::assertTrue($container->has(RecoveryCodeVerifier::class));
        self::assertTrue($container->has(TwoFactorManager::class));
        self::assertTrue($container->has(TwoFactorManagerInterface::class));

        // In-memory fallbacks
        self::assertTrue($container->has(InMemoryTotpReplayGuard::class));
        self::assertTrue($container->has(TotpReplayGuardInterface::class));
        self::assertTrue($container->has(InMemoryTotpSecretStore::class));
        self::assertTrue($container->has(TotpSecretStoreInterface::class));
        self::assertTrue($container->has(InMemoryRecoveryCodeStore::class));
        self::assertTrue($container->has(RecoveryCodeStoreInterface::class));
    }

    #[Test]
    public function wireRegistersAuthServicesWithTwoFactorDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(authEnabled: true, twoFactorEnabled: false);
        $configManager->load();

        $wiring = new AuthWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(PasswordHasher::class));
        self::assertTrue($container->has(AuthManager::class));
        self::assertTrue($container->has(Gate::class));
        self::assertTrue($container->has(AuthenticationMiddleware::class));
        self::assertTrue($container->has(AuthorizationMiddleware::class));

        // 2FA services should NOT be registered
        self::assertFalse($container->has(TwoFactorManager::class));
        self::assertFalse($container->has(TotpGenerator::class));
    }

    #[Test]
    public function wireSkipsWhenAuthIsNull(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(authEnabled: false, twoFactorEnabled: false);
        $configManager->load();

        $wiring = new AuthWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(AuthManager::class));
        self::assertFalse($container->has(PasswordHasher::class));
    }

    #[Test]
    public function wireRegistersRolesFromConfig(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManagerWithRoles();
        $configManager->load();

        $wiring = new AuthWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(RoleRegistryInterface::class));

        /** @var RoleRegistryInterface $registry */
        $registry = $container->get(RoleRegistryInterface::class);
        self::assertNotNull($registry->findByName('admin'));
        self::assertNotNull($registry->findByName('editor'));
    }

    private function createConfigManager(bool $authEnabled, bool $twoFactorEnabled): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_auth_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $twoFactorStr = $twoFactorEnabled ? 'true' : 'false';

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');

        if ($authEnabled) {
            file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => [], "auth" => ["default_guard" => "session", "two_factor" => ["enabled" => ' . $twoFactorStr . ', "issuer" => "PulsarTest", "allow_in_memory" => true], "authorization" => ["roles" => [], "super_roles" => []]]];');
        } else {
            file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        }

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithRoles(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_auth_wiring_roles_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => [], "auth" => ["default_guard" => "session", "two_factor" => ["enabled" => false], "authorization" => ["roles" => ["admin" => ["permissions" => ["manage-users"]], "editor" => ["permissions" => ["edit-posts"]]], "super_roles" => ["admin"]]]];');

        return new ConfigManager($configPath);
    }
}
