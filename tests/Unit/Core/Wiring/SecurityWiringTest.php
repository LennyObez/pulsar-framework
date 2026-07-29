<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Core\Wiring\SecurityWiring;
use Pulsar\DataProtection\DataProtectionConfig;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\CipherSuiteInterface;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManager;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;
use Pulsar\Security\Middleware\SecurityHeadersMiddleware;
use Pulsar\Security\Session\Flash\FlashBag;
use Pulsar\Security\Session\Handler\SessionHandlerInterface;
use Pulsar\Security\Session\SessionInterface;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\SessionMiddleware;
use Random\Randomizer;
use Stringable;

use function bin2hex;
use function file_put_contents;
use function implode;
use function mkdir;
use function random_bytes;
use function sodium_bin2hex;
use function sodium_crypto_secretbox_keygen;
use function str_repeat;

#[CoversClass(SecurityWiring::class)]
final class SecurityWiringTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PULSAR_MASTER_KEY');
    }

    #[Test]
    public function wireLoadsRetentionPoliciesFromDataProtectionConfig(): void
    {
        // Regression: config/data_protection.php was never loaded — SecurityWiring
        // read DataProtectionConfig from the config repository, which nothing
        // populates, so it always fell back to empty defaults and the operator's
        // GDPR retention policies were silently never enforced.
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(
            dataProtectionBody: "'retention' => [['category' => 'user_sessions', 'retention_days' => 90, 'legal_basis' => 'test']]",
        );
        $configManager->load();

        $wiring = new SecurityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue(
            $container->has(DataProtectionConfig::class),
            'config/data_protection.php must be loaded and its DTO registered, not ignored',
        );

        /** @var DataProtectionConfig $dp */
        $dp = $container->get(DataProtectionConfig::class);

        self::assertCount(1, $dp->retention, 'the operator retention policy from config must be honored');
        self::assertSame('user_sessions', $dp->retention[0]->category);
        self::assertSame(90, $dp->retention[0]->retentionDays);
    }

    #[Test]
    public function wireRegistersSessionAndCsrfWithoutMasterKey(): void
    {
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new SecurityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(HmacInterface::class));
        self::assertTrue($container->has(SessionHandlerInterface::class));
        self::assertTrue($container->has(SessionManager::class));
        self::assertTrue($container->has(SessionInterface::class));
        self::assertTrue($container->has(FlashBag::class));
        self::assertTrue($container->has(SessionMiddleware::class));
        self::assertTrue($container->has(CsrfTokenManager::class));
        self::assertTrue($container->has(CsrfTokenManagerInterface::class));
        self::assertTrue($container->has(CsrfMiddleware::class));
        self::assertTrue($container->has(SecurityHeadersMiddleware::class));
        self::assertFalse($container->has(MasterKey::class));
    }

    #[Test]
    public function wireRegistesCryptoWithMasterKey(): void
    {
        $masterKeyHex = sodium_bin2hex(sodium_crypto_secretbox_keygen());

        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(masterKeyHex: $masterKeyHex);
        $configManager->load();

        $wiring = new SecurityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(MasterKey::class));
        self::assertTrue($container->has(CipherSuiteInterface::class));
        self::assertTrue($container->has(EncryptorInterface::class));
    }

    #[Test]
    public function wireHandlesInvalidMasterKeyGracefully(): void
    {
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(masterKeyHex: 'invalid-hex');
        $configManager->load();

        $wiring = new SecurityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // Invalid key should be caught, crypto not registered, but session/csrf still work
        self::assertFalse($container->has(MasterKey::class));
        self::assertTrue($container->has(SessionManager::class));
        self::assertTrue($container->has(CsrfTokenManager::class));
    }

    #[Test]
    public function wireUsesArraySessionHandler(): void
    {
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(sessionHandler: 'array');
        $configManager->load();

        $wiring = new SecurityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(SessionHandlerInterface::class));
    }

    #[Test]
    public function wireRegistersWebAndApiMiddlewareGroups(): void
    {
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new SecurityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($middlewareRegistry->hasGroup('web'), 'web middleware group should be registered');
        self::assertTrue($middlewareRegistry->hasGroup('api'), 'api middleware group should be registered');
        self::assertTrue($middlewareRegistry->hasAlias('session'), 'session middleware alias should be registered');
        self::assertTrue($middlewareRegistry->hasAlias('csrf'), 'csrf middleware alias should be registered');
        self::assertTrue($middlewareRegistry->hasAlias('headers'), 'headers middleware alias should be registered');

        // Verify 'web' group resolves to 3 middleware (headers, session, csrf)
        $webMiddleware = $middlewareRegistry->resolve('web');
        self::assertCount(3, $webMiddleware);

        // Verify 'api' group resolves to 1 middleware (headers)
        $apiMiddleware = $middlewareRegistry->resolve('api');
        self::assertCount(1, $apiMiddleware);
    }

    #[Test]
    public function wirePipesSecurityHeadersMiddlewareGlobally(): void
    {
        // F9.1: SecurityHeadersMiddleware must be in the global pipeline so
        // every response — including routes that do not opt into the
        // `web` / `api` middleware groups (diagnostics endpoints, ad-hoc
        // JSON APIs, error pages) — carries the baseline security headers.
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new SecurityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // Send a request through the pipeline with a no-op handler and
        // verify the response received the SecurityHeadersMiddleware
        // baseline. This is a behavioural assertion — independent of how
        // many middlewares the pipeline holds.
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new \Pulsar\Http\Message\Response();
            }
        };

        $request = new \Pulsar\Http\Message\ServerRequest(method: 'GET', uri: '/no-group');
        $response = $middleware->process($request, $handler);

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }

    #[Test]
    public function wireLogsWarningWhenLiteralHeaderShadowsStructuredConfig(): void
    {
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if ($level === LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $container->instance(LoggerInterface::class, $logger);

        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        // A literal Strict-Transport-Security that disagrees with the typed hsts
        // block (preload differs) must trigger a one-time boot warning.
        $configManager = $this->createConfigManager(
            headersBody: '"Strict-Transport-Security" => "max-age=63072000; includeSubDomains; preload", "hsts" => ["enabled" => true, "preload" => false]',
        );
        $configManager->load();

        $wiring = new SecurityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertNotEmpty($logger->warnings);
        self::assertStringContainsString('Strict-Transport-Security', implode("\n", $logger->warnings));
    }

    #[Test]
    public function wireDoesNotFloodShadowedHeaderWarningInProduction(): void
    {
        // The shadowed-header warning is a CONFIG invariant: under PHP-FPM
        // (boot==request) an unconditional boot log would repeat on every
        // request. In production (logAtBoot off by default) it must be silent —
        // the override is surfaced during development instead.
        $originalEnv = getenv('APP_ENV');
        putenv('APP_ENV=production');

        try {
            $container = new Container();
            $container->instance(Randomizer::class, new Randomizer());

            $logger = new class extends AbstractLogger {
                /** @var list<string> */
                public array $warnings = [];

                public function log(mixed $level, string|Stringable $message, array $context = []): void
                {
                    if ($level === LogLevel::WARNING) {
                        $this->warnings[] = (string) $message;
                    }
                }
            };
            $container->instance(LoggerInterface::class, $logger);

            $router = new Router();
            $middleware = new MiddlewarePipeline($container);
            $middlewareRegistry = new MiddlewareRegistry();

            $configManager = $this->createConfigManager(
                masterKeyHex: str_repeat('ab', 32),
                headersBody: '"Strict-Transport-Security" => "max-age=63072000; includeSubDomains; preload", "hsts" => ["enabled" => true, "preload" => false]',
            );
            $configManager->load();

            $wiring = new SecurityWiring();
            $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

            self::assertStringNotContainsString(
                'overrides the structured',
                implode("\n", $logger->warnings),
                'The shadowed-header warning must not flood the log on every production boot',
            );
        } finally {
            if ($originalEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $originalEnv);
            }
        }
    }

    #[Test]
    public function wireDoesNotWarnForDefaultHeaders(): void
    {
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer());

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if ($level === LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $container->instance(LoggerInterface::class, $logger);

        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new SecurityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertSame([], $logger->warnings);
    }

    #[Test]
    public function wireResolvesMasterKeyFromEnvFileAndDoesNotReportItMissing(): void
    {
        // Acceptance (Request 1): PULSAR_MASTER_KEY lives ONLY in .env, never the
        // OS process env. A production boot must (a) build the MasterKey — so the
        // CSRF/session crypto key is identical across FPM workers — and (b) NOT
        // emit the spurious "master_key_present" warning that getenv() produced.
        $originalEnv = getenv('APP_ENV');
        $originalKey = getenv('PULSAR_MASTER_KEY');
        // Force the production posture gate to run; ensure the key is NOT in OS env.
        putenv('APP_ENV=production');
        putenv('PULSAR_MASTER_KEY');

        try {
            $key = str_repeat('ab', 32); // valid 64-hex-char master key

            $container = new Container();
            $container->instance(Randomizer::class, new Randomizer());

            $logger = new class extends AbstractLogger {
                /** @var list<string> */
                public array $warnings = [];

                public function log(mixed $level, string|Stringable $message, array $context = []): void
                {
                    if ($level === LogLevel::WARNING) {
                        $this->warnings[] = (string) $message;
                    }
                }
            };
            $container->instance(LoggerInterface::class, $logger);

            $router = new Router();
            $middleware = new MiddlewarePipeline($container);
            $middlewareRegistry = new MiddlewareRegistry();

            $configManager = $this->createConfigManager(
                envFileContent: "PULSAR_MASTER_KEY={$key}\n",
            );
            $configManager->load();

            $wiring = new SecurityWiring();
            $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

            // (a) The crypto stack received the .env key — cross-worker consistency.
            self::assertTrue(
                $container->has(MasterKey::class),
                'MasterKey must be built from the .env-provided key so all workers share it',
            );

            // (b) No false "master key missing" posture warning.
            $joined = implode("\n", $logger->warnings);
            self::assertStringNotContainsString('master_key_present', $joined);
            self::assertStringNotContainsString('PULSAR_MASTER_KEY environment variable is not set', $joined);
        } finally {
            if ($originalEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $originalEnv);
            }
            if ($originalKey === false) {
                putenv('PULSAR_MASTER_KEY');
            } else {
                putenv('PULSAR_MASTER_KEY=' . $originalKey);
            }
        }
    }

    private function createConfigManager(?string $masterKeyHex = null, string $sessionHandler = 'file', string $headersBody = '', ?string $envFileContent = null, ?string $dataProtectionBody = null): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_security_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        if ($dataProtectionBody !== null) {
            file_put_contents($configPath . '/data_protection.php', '<?php return [' . $dataProtectionBody . '];');
        }

        // Set PULSAR_MASTER_KEY env var if provided (use putenv for getenv() compatibility)
        if ($masterKeyHex !== null) {
            putenv('PULSAR_MASTER_KEY=' . $masterKeyHex);
        } else {
            putenv('PULSAR_MASTER_KEY');
        }

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []], "audit" => ["enabled" => false]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => ["handler" => "' . $sessionHandler . '", "lifetime" => 120, "encryption" => false, "validators" => []], "csrf" => [], "headers" => [' . $headersBody . '], "rate_limit" => [], "cors" => ["enabled" => false]];');

        // Optionally write a .env file and wire it into the ConfigManager so the
        // Environment repository loads it (mirrors a real deployment whose secrets
        // live in .env rather than the OS process env).
        $envFilePath = null;
        if ($envFileContent !== null) {
            $envFilePath = $configPath . '/.env';
            file_put_contents($envFilePath, $envFileContent);
        }

        $configManager = new ConfigManager($configPath, $envFilePath);
        // SecurityWiring owns config/data_protection.php and config/domains.php;
        // register its loaders so load() builds those DTOs into the repository,
        // exactly as Kernel does at boot.
        ConfigLoaderRegistrar::register($configManager, [new SecurityWiring()]);

        return $configManager;
    }
}
