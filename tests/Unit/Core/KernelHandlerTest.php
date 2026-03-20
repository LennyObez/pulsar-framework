<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\BootProfile;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Route;
use Pulsar\Routing\RoutingException;

use function assert;
use function bin2hex;
use function file_put_contents;
use function is_dir;
use function is_string;
use function mkdir;
use function random_bytes;
use function strlen;

#[CoversClass(Kernel::class)]
#[CoversClass(BootProfile::class)]
final class KernelHandlerTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_handler_test_' . bin2hex(random_bytes(8));
        mkdir($this->configPath, 0o750, true);
        $this->writeConfigStubs($this->configPath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->configPath);
    }

    #[Test]
    public function handleDispatchesCallableRouteHandler(): void
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/test',
            handler: fn(ServerRequestInterface $req): ResponseInterface => Response::html('OK'),
        ));

        $request = $this->createRequest('GET', '/test');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', (string) $response->getBody());
    }

    #[Test]
    public function handleReturnsStringAsHtmlResponse(): void
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/hello',
            handler: fn(ServerRequestInterface $req): string => 'Hello World',
        ));

        $request = $this->createRequest('GET', '/hello');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hello World', (string) $response->getBody());
    }

    /**
     * F4.11: with no `ExceptionHandler` registered the kernel used to
     * re-throw and let the SAPI emit a default error page (file paths
     * and stack trace leak). The fallback now renders a generic 404
     * via `ProductionRenderer` so the response is leak-free.
     */
    #[Test]
    public function handleReturns404OnNoRouteMatchWithoutExceptionHandler(): void
    {
        $kernel = new Kernel();

        $request = $this->createRequest('GET', '/nonexistent');

        $response = $kernel->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function handleInvokableController(): void
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/invokable',
            handler: HandlerInvokableController::class,
        ));

        $request = $this->createRequest('GET', '/invokable');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('invoked', (string) $response->getBody());
    }

    #[Test]
    public function handleArrayControllerMethod(): void
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/array',
            handler: [HandlerArrayController::class, 'index'],
        ));

        $request = $this->createRequest('GET', '/array');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('array-index', (string) $response->getBody());
    }

    #[Test]
    public function handleRouteWithParameters(): void
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/users/{id}',
            handler: static function (ServerRequestInterface $req, array $params): ResponseInterface {
                $id = $params['id'];
                assert(is_string($id));

                return Response::html('user:' . $id);
            },
        ));

        $request = $this->createRequest('GET', '/users/42');
        $response = $kernel->handle($request);

        self::assertSame('user:42', (string) $response->getBody());
    }

    #[Test]
    public function handleRouteWithNamedParameters(): void
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/items/{slug}',
            handler: [HandlerNamedParamController::class, 'show'],
        ));

        $request = $this->createRequest('GET', '/items/my-item');
        $response = $kernel->handle($request);

        self::assertSame('slug:my-item', (string) $response->getBody());
    }

    #[Test]
    public function handleAutoBoots(): void
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/auto',
            handler: fn(ServerRequestInterface $req): ResponseInterface => Response::html('auto'),
        ));

        self::assertFalse($kernel->booted);

        $request = $this->createRequest('GET', '/auto');
        $kernel->handle($request);

        self::assertTrue($kernel->booted);
    }

    #[Test]
    public function bootProfileIsAvailableAfterBoot(): void
    {
        $configManager = new ConfigManager($this->configPath);
        $kernel = new Kernel(configManager: $configManager);

        self::assertNull($kernel->bootProfile());

        $kernel->boot();

        $profile = $kernel->bootProfile();

        self::assertNotNull($profile);
        self::assertGreaterThanOrEqual(0, $profile->totalUs);
        self::assertGreaterThanOrEqual(0, $profile->configUs);
        self::assertFalse($profile->cacheHit);
    }

    #[Test]
    public function bootProfileToArrayReturnsExpectedKeys(): void
    {
        $profile = new BootProfile(
            totalUs: 1000,
            cacheLoadUs: 100,
            configUs: 500,
            extensionRegisterUs: 100,
            extensionBootUs: 200,
            compilerPassPhaseUs: 50,
            cacheHit: true,
            routesCached: false,
        );

        $array = $profile->toArray();

        self::assertSame(1000, $array['total_us']);
        self::assertSame(100, $array['cache_load_us']);
        self::assertSame(500, $array['config_us']);
        self::assertSame(100, $array['extension_register_us']);
        self::assertSame(200, $array['extension_boot_us']);
        self::assertSame(50, $array['compiler_pass_us']);
        self::assertTrue($array['cache_hit']);
        self::assertFalse($array['routes_cached']);
    }

    #[Test]
    public function extensionBootstrapReturnsNullByDefault(): void
    {
        $kernel = new Kernel();

        self::assertNull($kernel->extensionBootstrap());
    }

    #[Test]
    public function configManagerReturnsNullByDefault(): void
    {
        $kernel = new Kernel();

        self::assertNull($kernel->configManager());
    }

    #[Test]
    public function configManagerReturnsInstanceWhenProvided(): void
    {
        $configManager = new ConfigManager($this->configPath);
        $kernel = new Kernel(configManager: $configManager);

        self::assertSame($configManager, $kernel->configManager());
    }

    #[Test]
    public function middlewareRegistryIsAccessible(): void
    {
        $kernel = new Kernel();

        self::assertInstanceOf(MiddlewareRegistry::class, $kernel->middlewareRegistry());
    }

    #[Test]
    public function addMiddlewareReturnsKernelForChaining(): void
    {
        $kernel = new Kernel();

        $result = $kernel->addMiddleware(new HandlerPassthroughMiddleware());

        self::assertSame($kernel, $result);
    }

    #[Test]
    public function shutdownResetsBootedState(): void
    {
        $kernel = new Kernel();
        $kernel->boot();

        self::assertTrue($kernel->booted);

        $kernel->shutdown();

        self::assertFalse($kernel->booted);
    }

    /**
     * F2.18: once boot() runs the middleware pipeline is cached;
     * a post-boot addMiddleware() call would mutate the cached
     * pipeline silently and only the next request would observe
     * the new middleware. Refuse the mutation explicitly so the
     * caller sees the issue at the call site.
     */
    #[Test]
    public function addMiddlewareAfterBootThrows(): void
    {
        $kernel = new Kernel();
        $kernel->boot();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('addMiddleware() cannot be called after the kernel has booted');

        $kernel->addMiddleware(new HandlerPassthroughMiddleware());
    }

    #[Test]
    public function handleWithRouteMiddleware(): void
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/with-middleware',
            handler: fn(ServerRequestInterface $req): ResponseInterface => Response::html('ok'),
            middleware: [HandlerPassthroughMiddleware::class],
        ));

        $request = $this->createRequest('GET', '/with-middleware');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * F4.11: non-callable handler used to bubble RoutingException
     * out of handle(); now the fallback ProductionRenderer renders
     * a 500 response instead.
     */
    #[Test]
    public function handleNonCallableHandlerReturns500WithoutExceptionHandler(): void
    {
        $kernel = new Kernel();

        // Pass an intentionally non-callable/non-class-string handler via a mixed container
        /** @var array{handler: callable|class-string} $config */
        $config = json_decode('{"handler": "not_a_class_or_callable"}', true, 512, JSON_THROW_ON_ERROR);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/bad',
            handler: $config['handler'],
        ));

        $request = $this->createRequest('GET', '/bad');

        $response = $kernel->handle($request);

        self::assertSame(500, $response->getStatusCode());
    }

    private function createRequest(string $method, string $path): ServerRequestInterface
    {
        return new ServerRequest(
            method: $method,
            uri: 'http://localhost' . $path,
        );
    }

    private function writeConfigStubs(string $configPath): void
    {
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['name' => 'test', 'env' => 'testing', 'debug' => true, 'timezone' => 'UTC', 'locale' => 'en'];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'observability.php',
            "<?php\nreturn ['logging' => ['default_channel' => 'file', 'level' => 'info', 'channels' => []], 'metrics' => ['enabled' => false, 'exporters' => []], 'tracing' => ['enabled' => false, 'sampling_rate' => 0.0], 'error_tracking' => ['enabled' => false, 'max_groups' => 100, 'max_recent_events_per_group' => 5, 'sensitive_fields' => []], 'audit' => ['enabled' => false, 'log_path' => '/dev/null', 'events' => []]];\n",
        );
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'security.php',
            "<?php\nreturn ['session' => ['cookie_name' => 'TEST', 'lifetime' => 3600, 'cookie_httponly' => true, 'cookie_secure' => false, 'cookie_samesite' => 'Lax', 'regenerate_on_privilege_change' => true], 'csrf' => ['enabled' => false, 'token_length' => 32, 'header_name' => 'X-CSRF-Token', 'form_field_name' => '_csrf'], 'headers' => [], 'rate_limiting' => ['enabled' => false, 'default_limit' => 60, 'default_window' => 60], 'auth' => ['default_guard' => 'session', 'guards' => [], 'two_factor' => ['enabled' => false, 'issuer' => 'Test', 'code_digits' => 6, 'code_period' => 30, 'verification_window' => 1, 'recovery_code_count' => 8], 'authorization' => ['roles' => [], 'super_roles' => []]]];\n",
        );
    }

    /**
     * F3.3: route cleanup through SafeFilesystem so the test fixture
     * does not depend on bare `unlink()` (static-analysis flag) and
     * benefits from the same path-traversal guards as production.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            return;
        }

        $relative = str_starts_with($dir, $cwd)
            ? ltrim(substr($dir, strlen($cwd)), '/\\')
            : $dir;

        $safe = \Pulsar\Filesystem\SafePath::resolveUnderCwd($relative);
        if ($safe === null) {
            return;
        }

        new \Pulsar\Filesystem\SafeFilesystem()->removeDirectoryRecursive($safe);
    }
}

class HandlerInvokableController
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return Response::html('invoked');
    }
}

class HandlerArrayController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return Response::html('array-index');
    }
}

class HandlerNamedParamController
{
    public function show(ServerRequestInterface $request, string $slug): ResponseInterface
    {
        return Response::html('slug:' . $slug);
    }
}

class HandlerPassthroughMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return $handler->handle($request);
    }
}
