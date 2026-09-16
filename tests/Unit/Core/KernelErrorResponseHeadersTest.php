<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\ErrorHandling\RenderableInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use RuntimeException;

use function file_get_contents;
use function file_put_contents;
use function ini_get;
use function ini_set;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * An error produced OUTSIDE the middleware pipeline still has to ship with the
 * protective headers.
 *
 * `Kernel::handle()`'s outer catch is reached when boot() fails or a global
 * middleware throws. A throw from a global middleware unwinds every frame
 * outside it — SecurityHeadersMiddleware's included — and a boot failure happens
 * before that middleware exists at all, so nothing downstream is left to add
 * anything. These responses used to leave the kernel with no CSP, no framing
 * policy and no referrer policy: the response most likely to be probed was the
 * only bare one in the framework.
 */
#[CoversClass(Kernel::class)]
final class KernelErrorResponseHeadersTest extends TestCase
{
    private string $tempDir;

    private string $errorLog;

    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_kernel_error_headers_' . uniqid();
        mkdir($this->tempDir, 0o777, true);

        $this->errorLog = $this->tempDir . '/php-error.log';
        $this->previousErrorLog = ini_get('error_log');
        file_put_contents($this->tempDir . '/app.php', "<?php\nreturn ['debug' => false];");
        file_put_contents($this->tempDir . '/security.php', "<?php\nreturn [];");
        file_put_contents($this->tempDir . '/observability.php', <<<'PHP'
            <?php
            return [
                'metrics' => ['enabled' => false],
                'tracing' => ['enabled' => false],
                'error_tracking' => ['enabled' => false],
                'logging' => ['level' => 'error', 'handlers' => []],
            ];
            PHP);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function aThrowingGlobalMiddlewareStillShipsTheProtectiveHeaders(): void
    {
        $kernel = new Kernel();
        $kernel->addMiddleware(new ExplodingGlobalMiddleware());
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/x',
            handler: static fn(): ResponseInterface => Response::html('ok'),
        ));

        $this->captureErrorLog();
        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/x'));

        self::assertSame(500, $response->getStatusCode());
        $this->assertCarriesProtectiveHeaders($response);

        // The generic page tells the client nothing, so the operator has to be
        // told somewhere. This is the only place it is said.
        self::assertStringContainsString(
            'global middleware exploded',
            $this->errorLogContents(),
            'the last-resort path rendered the page and logged nothing',
        );
    }

    #[Test]
    public function anApplicationErrorPageBuiltOutsideThePipelineIsHardened(): void
    {
        // The realistic shape: a booted application, its own ExceptionHandler,
        // and an error page that carries only the Content-Type it chose. Nothing
        // downstream of the outer catch adds a header, so the kernel does.
        $kernel = $this->bootedKernel(new ExplodingGlobalMiddleware(new BareErrorPageException()));

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/x'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('bare error page', (string) $response->getBody());
        $this->assertCarriesProtectiveHeaders($response);
    }

    #[Test]
    public function hardeningFillsGapsAndDoesNotOverruleAStatedValue(): void
    {
        // The kernel's list is not the application's configured header policy.
        // Replacing a value the error page deliberately set would be the kernel
        // inventing policy on a path the operator never sees; filling in the
        // ones nobody stated cannot.
        $kernel = $this->bootedKernel(new ExplodingGlobalMiddleware(new FramedErrorPageException()));

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/x'));

        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ProductionRenderer::LAST_RESORT_HEADERS['Content-Security-Policy'],
            $response->getHeaderLine('Content-Security-Policy'),
        );
    }

    #[Test]
    public function theFallbackErrorPageCarriesTheHeadersOnEveryStatus(): void
    {
        // 404 and 405 travel the same fallback renderer as a 500 and are just as
        // reachable by a prober.
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::POST],
            path: '/only-post',
            handler: static fn(): ResponseInterface => Response::html('ok'),
        ));

        $notFound = $kernel->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/nowhere'));
        self::assertSame(404, $notFound->getStatusCode());
        $this->assertCarriesProtectiveHeaders($notFound);

        $notAllowed = $kernel->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/only-post'));
        self::assertSame(405, $notAllowed->getStatusCode());
        $this->assertCarriesProtectiveHeaders($notAllowed);
        // RFC 9110 §15.5.6 — still advertised alongside the new headers.
        self::assertNotSame('', $notAllowed->getHeaderLine('Allow'));
    }

    /**
     * Point PHP's error log at a fixture for the remainder of this test.
     *
     * The fallback renderer calls error_log() on a 5xx, and that is the point of
     * the branch: it runs when nothing else logged the failure, so the line is the
     * only thing the operator ever gets. Redirected so it can be ASSERTED instead
     * of escaping into the runner, where PHPUnit counts it as unexpected output and
     * the suite's failOnRisky turns the whole run red.
     *
     * Called from the test body rather than setUp(): PHPUnit installs its own
     * error-log destination BETWEEN setUp() and the test method, so a redirect set
     * any earlier is discarded.
     */
    private function captureErrorLog(): void
    {
        ini_set('error_log', $this->errorLog);
    }

    private function errorLogContents(): string
    {
        return is_file($this->errorLog) ? (string) file_get_contents($this->errorLog) : '';
    }

    private function assertCarriesProtectiveHeaders(ResponseInterface $response): void
    {
        foreach (ProductionRenderer::LAST_RESORT_HEADERS as $name => $value) {
            if ($name === 'Content-Type') {
                continue;
            }

            self::assertSame($value, $response->getHeaderLine($name), $name . ' is missing from an error response');
        }
    }

    private function bootedKernel(MiddlewareInterface $middleware): Kernel
    {
        $kernel = new Kernel(configManager: new ConfigManager(configPath: $this->tempDir));
        $kernel->addMiddleware($middleware);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/x',
            handler: static fn(): ResponseInterface => Response::html('ok'),
        ));

        return $kernel;
    }

    private function removeDir(string $dir): void
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
                $this->removeDir($path);

                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}

final class ExplodingGlobalMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ?RuntimeException $failure = null) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw $this->failure ?? new RuntimeException('global middleware exploded');
    }
}

/**
 * An application error page with nothing on it but its media type — what an
 * exception handler routinely produces, and what used to reach the client bare.
 */
final class BareErrorPageException extends RuntimeException implements RenderableInterface
{
    public function render(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            statusCode: 503,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: 'bare error page',
        );
    }
}

/**
 * An error page that states its own framing policy and media type. Both are
 * deliberate, and both survive the hardening.
 */
final class FramedErrorPageException extends RuntimeException implements RenderableInterface
{
    public function render(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            statusCode: 503,
            headers: [
                'Content-Type' => 'application/problem+json',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
            body: '{"title":"Service Unavailable"}',
        );
    }
}
