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
use Pulsar\Core\Wiring\SecurityWiring;
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
use Pulsar\Security\Session\Session;
use Pulsar\Security\Session\SessionInterface;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\SessionMiddleware;
use Random\Randomizer;
use Stringable;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sodium_bin2hex;
use function sodium_crypto_secretbox_keygen;

#[CoversClass(SecurityWiring::class)]
final class SecurityWiringTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PULSAR_MASTER_KEY');
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
        self::assertTrue($container->has(Session::class));
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
    public function legacySessionAliasPointsToSessionManager(): void
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

        // Session::class must resolve to the same SessionManager instance,
        // not a separate Session object (which would cause dual-session bugs).
        $sessionManager = $container->get(SessionManager::class);
        $legacySession = $container->get(Session::class);

        self::assertSame(
            $sessionManager,
            $legacySession,
            'Session::class alias must point to the SessionManager instance, not a separate Session',
        );
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

    private function createConfigManager(?string $masterKeyHex = null, string $sessionHandler = 'file', string $headersBody = ''): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_security_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        // Set PULSAR_MASTER_KEY env var if provided (use putenv for getenv() compatibility)
        if ($masterKeyHex !== null) {
            putenv('PULSAR_MASTER_KEY=' . $masterKeyHex);
        } else {
            putenv('PULSAR_MASTER_KEY');
        }

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []], "audit" => ["enabled" => false]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => ["handler" => "' . $sessionHandler . '", "lifetime" => 120, "encryption" => false, "validators" => []], "csrf" => [], "headers" => [' . $headersBody . '], "rate_limit" => [], "cors" => ["enabled" => false]];');

        return new ConfigManager($configPath);
    }
}
