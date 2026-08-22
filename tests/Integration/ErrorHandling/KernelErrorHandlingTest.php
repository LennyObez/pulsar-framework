<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Http\Message\BodyTooLargeException;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\Validation\ValidationException;
use Pulsar\Http\Validation\ValidationResult;
use Pulsar\Http\Validation\Violation;
use ReflectionMethod;
use RuntimeException;
use Throwable;

use function ini_get;
use function preg_replace;
use function strip_tags;
use function strlen;

#[CoversClass(Kernel::class)]
#[CoversClass(ExceptionHandler::class)]
#[CoversClass(ProductionRenderer::class)]
final class KernelErrorHandlingTest extends TestCase
{
    private string $tempDir;
    private string $errorLogFile;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        // Cwd-rooted temp dir: SafePath refuses anything outside the working
        // directory, so a system temp dir would be rejected by the cleanup helper.
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->tempDir = $cwd . '/var/tmp_pulsar_kernel_err_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);
        $this->writeConfigFiles(debug: true);

        $this->errorLogFile = $this->tempDir . '/error.log';
        $this->previousErrorLog = (string) ini_get('error_log');

        putenv('APP_NAME');
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('LOG_LEVEL');
        putenv('LOG_CHANNEL');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);

        putenv('APP_NAME');
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('LOG_LEVEL');
        putenv('LOG_CHANNEL');

        $this->cleanDir($this->tempDir);
    }

    /**
     * Point PHP's error log at the fixture for the remainder of this test.
     *
     * The last-resort path tells the client nothing, so everything it knows
     * goes to the error log — which is what these tests assert. Called from
     * inside the test body rather than setUp() because PHPUnit installs its own
     * error-log destination *between* setUp() and the test method: a redirect
     * set any earlier is discarded, and the line lands in PHPUnit's capture as
     * stray output instead of somewhere the test can read it.
     */
    private function captureErrorLog(): void
    {
        ini_set('error_log', $this->errorLogFile);
    }

    private function errorLogContents(): string
    {
        return is_file($this->errorLogFile)
            ? (string) file_get_contents($this->errorLogFile)
            : '';
    }

    /**
     * Routes cleanup through SafeFilesystem rather than a bare `unlink()`, so the
     * fixture is subject to the same path-traversal guards as production code.
     */
    private function cleanDir(string $dir): void
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

    private function writeConfigFiles(bool $debug = true): void
    {
        $debugStr = $debug ? 'true' : 'false';
        file_put_contents($this->tempDir . '/app.php', "<?php return [
            'name' => 'TestApp',
            'env' => 'local',
            'debug' => {$debugStr},
            'timezone' => 'UTC',
            'locale' => 'en',
        ];");

        file_put_contents($this->tempDir . '/observability.php', '<?php return [
            "logging" => [
                "default_channel" => "null",
                "level" => "debug",
                "channels" => [
                    "null" => ["driver" => "stream", "stream" => "php://memory"],
                ],
            ],
            "audit" => [
                "enabled" => false,
                "log_path" => "var/logs/audit.jsonl",
                "events" => [],
            ],
        ];');

        file_put_contents($this->tempDir . '/security.php', '<?php return [
            "session" => [],
            "csrf" => ["enabled" => false],
            "headers" => [],
            "rate_limiting" => ["enabled" => false],
        ];');
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function createRequest(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: $path,
            headers: $headers,
        );
    }

    private function createKernel(bool $debug = true): Kernel
    {
        $this->writeConfigFiles(debug: $debug);
        $configManager = new ConfigManager(configPath: $this->tempDir);

        return new Kernel(configManager: $configManager);
    }

    #[Test]
    public function handlerThrowsReturnsErrorResponse(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/error', fn() => throw new RuntimeException('Handler failed'));

        $response = $kernel->handle($this->createRequest(path: '/error'));

        self::assertSame(ResponseStatus::InternalServerError->value, $response->getStatusCode());
    }

    #[Test]
    public function missingRouteReturns404(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/', fn() => Response::text('home'));

        $response = $kernel->handle($this->createRequest(path: '/nonexistent'));

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function wrongMethodReturns405(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/test', fn() => Response::text('ok'));

        $response = $kernel->handle($this->createRequest('POST', '/test'));

        self::assertSame(ResponseStatus::MethodNotAllowed->value, $response->getStatusCode());
        self::assertNotEmpty($response->getHeaderLine('Allow'));
    }

    #[Test]
    public function debugModeShowsTrace(): void
    {
        $kernel = $this->createKernel(debug: true);
        $kernel->router()->get('/error', fn() => throw new RuntimeException('Debug visible'));

        $response = $kernel->handle($this->createRequest(path: '/error'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('Debug visible', $body);
        self::assertStringContainsString('RuntimeException', $body);
    }

    #[Test]
    public function productionModeHidesDetails(): void
    {
        $kernel = $this->createKernel(debug: false);
        $kernel->router()->get('/error', fn() => throw new RuntimeException('Secret error info'));

        $response = $kernel->handle($this->createRequest(path: '/error'));

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('Secret error info', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
    }

    #[Test]
    public function middlewareExceptionCaught(): void
    {
        $kernel = $this->createKernel();
        $kernel->addMiddleware(new ThrowingMiddleware());
        $kernel->router()->get('/', fn() => Response::text('ok'));

        $response = $kernel->handle($this->createRequest());

        self::assertSame(ResponseStatus::InternalServerError->value, $response->getStatusCode());
    }

    #[Test]
    public function httpExceptionUsesCustomStatus(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/forbidden', fn() => throw HttpException::forbidden('No access'));

        $response = $kernel->handle($this->createRequest(path: '/forbidden'));

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function errorResponsesCarrySameSecurityHeadersAsSuccess(): void
    {
        // Error responses must leave through the same global pipeline as a 200,
        // or SecurityHeadersMiddleware never sees them: an unprotected 404 or
        // 500 body is still attacker-reachable and still framable.
        $securityHeaders = [
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Referrer-Policy',
            'X-XSS-Protection',
            'Permissions-Policy',
            'X-Permitted-Cross-Domain-Policies',
            'Content-Security-Policy',
            'Cross-Origin-Opener-Policy',
            'Cross-Origin-Embedder-Policy',
            'Cross-Origin-Resource-Policy',
        ];

        $kernel = $this->createKernel();
        $kernel->router()->get('/ok', fn() => Response::text('ok'));
        $kernel->router()->get('/boom', fn() => throw new RuntimeException('boom'));
        $kernel->router()->get('/nope', fn() => throw HttpException::forbidden('No access'));

        $success = $kernel->handle($this->createRequest(path: '/ok'));
        self::assertSame(200, $success->getStatusCode());

        $baseline = [];
        foreach ($securityHeaders as $name) {
            $value = $success->getHeaderLine($name);
            self::assertNotSame('', $value, "The 200 baseline is missing security header {$name}");
            $baseline[$name] = $value;
        }

        $errorResponses = [
            '404' => $kernel->handle($this->createRequest(path: '/does-not-exist')),
            '405' => $kernel->handle($this->createRequest('POST', '/ok')),
            '500' => $kernel->handle($this->createRequest(path: '/boom')),
            '403' => $kernel->handle($this->createRequest(path: '/nope')),
        ];

        foreach ($errorResponses as $label => $response) {
            self::assertGreaterThanOrEqual(
                400,
                $response->getStatusCode(),
                "The {$label} case must be an error status",
            );

            foreach ($baseline as $name => $value) {
                self::assertSame(
                    $value,
                    $response->getHeaderLine($name),
                    "The {$label} error response header {$name} must match the 200 baseline",
                );
                self::assertCount(
                    1,
                    $response->getHeader($name),
                    "The {$label} error response emitted {$name} more than once",
                );
            }
        }

        // 405 must still advertise the permitted methods alongside the security headers.
        self::assertNotEmpty($errorResponses['405']->getHeaderLine('Allow'));
    }

    /**
     * With no ExceptionHandler registered the kernel must not re-throw: an
     * uncaught exception would reach the SAPI default error page and leak the
     * stack trace. The fallback renders a generic 500 via ProductionRenderer.
     */
    #[Test]
    public function kernelWithoutConfigManagerReturns500FallbackResponse(): void
    {
        $this->captureErrorLog();

        $kernel = new Kernel();
        $kernel->router()->get('/error', fn() => throw new RuntimeException('No handler'));

        $response = $kernel->handle($this->createRequest(path: '/error'));

        self::assertSame(ResponseStatus::InternalServerError->value, $response->getStatusCode());
        $body = (string) $response->getBody();
        // Generic fallback page — no leak of the exception class /
        // message / stack trace.
        self::assertStringNotContainsString('No handler', $body);
        self::assertStringNotContainsString('RuntimeException', $body);

        // Silent is not the same as safe: with no handler wired, nothing else
        // records the failure.
        self::assertStringContainsString('No handler', $this->errorLogContents());
    }

    #[Test]
    public function configManagerAccessor(): void
    {
        $kernel = $this->createKernel();

        self::assertNotNull($kernel->configManager());
    }

    #[Test]
    public function validationExceptionReturnsJson422(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/validate', function () {
            $result = new ValidationResult([
                new Violation('email', 'The email field is required.', 'required'),
            ]);
            throw new ValidationException($result);
        });

        $response = $kernel->handle($this->createRequest(path: '/validate'));

        self::assertSame(ResponseStatus::UnprocessableEntity->value, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array{error: string, status: int, violations: list<mixed>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Validation Failed', $data['error']);
        self::assertSame(422, $data['status']);
        self::assertCount(1, $data['violations']);
    }

    #[Test]
    public function acceptJsonHeaderReturnsJsonError(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/error', fn() => throw new RuntimeException('Server error'));

        $response = $kernel->handle($this->createRequest(path: '/error', headers: ['Accept' => 'application/json']));

        self::assertSame(ResponseStatus::InternalServerError->value, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array{error: string, status: int} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Internal Server Error', $data['error']);
        self::assertSame(500, $data['status']);
    }

    #[Test]
    public function acceptJsonWith404ReturnsJsonError(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/', fn() => Response::text('home'));

        $response = $kernel->handle($this->createRequest(path: '/nonexistent', headers: ['Accept' => 'application/json']));

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());

        /** @var array{error: string, status: int} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Not Found', $data['error']);
        self::assertSame(404, $data['status']);
    }

    /**
     * `boot()` used to run outside `handle()`'s try block. A config file that
     * throws therefore escaped the kernel entirely and was rendered by the
     * SAPI: exception class, message, absolute source path and stack frames,
     * appended to whatever had already been written — so the status line could
     * still read 200.
     *
     * The poisoned file puts its own absolute path into the exception message,
     * which makes any leak of the message a leak of the path.
     */
    #[Test]
    public function poisonedConfigRendersGenericErrorWithoutPaths(): void
    {
        $this->captureErrorLog();
        $this->writeConfigFiles(debug: false);
        file_put_contents(
            $this->tempDir . '/app.php',
            "<?php throw new \\RuntimeException('POISONED CONFIG ' . __FILE__);",
        );

        $kernel = new Kernel(configManager: new ConfigManager(configPath: $this->tempDir));
        $kernel->router()->get('/', fn() => Response::text('never reached'));

        $response = $kernel->handle($this->createRequest());

        self::assertSame(ResponseStatus::InternalServerError->value, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('POISONED CONFIG', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString($this->tempDir, $body);
        self::assertStringNotContainsString('.php', $body);
        self::assertStringNotContainsString('#0', $body);
        self::assertNoPathSeparatorInText($body);

        // The operator still gets the whole story — a boot failure that leaves
        // no trace anywhere would be a different kind of broken.
        self::assertStringContainsString('POISONED CONFIG', $this->errorLogContents());
    }

    /**
     * `ServerRequest::fromGlobals()` runs before a request object exists, so a
     * body over the 10 MiB cap raises `BodyTooLargeException` where no
     * middleware and no exception handler can see it. Measured before the fix:
     * HTTP 200 plus `Uncaught Pulsar\Http\Message\BodyTooLargeException`, the
     * byte counts, the absolute path of the exception class and the call stack.
     */
    #[Test]
    public function oversizedBodyBeforeRequestExistsRendersGeneric413(): void
    {
        $response = $this->preRequestFailureResponse(
            new Kernel(),
            BodyTooLargeException::exceedsLimit(10_485_761, 10_485_760),
        );

        self::assertSame(ResponseStatus::PayloadTooLarge->value, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('BodyTooLargeException', $body);
        self::assertStringNotContainsString('10485761', $body);
        self::assertStringNotContainsString('.php', $body);
        self::assertNoPathSeparatorInText($body);
    }

    /**
     * Anything else that stops the request being parsed is a malformed request,
     * not a server fault, and must not report a status the client can mistake
     * for one.
     */
    #[Test]
    public function unparseableRequestBeforePipelineRendersGeneric400(): void
    {
        $response = $this->preRequestFailureResponse(
            new Kernel(),
            new RuntimeException('malformed request line at /srv/app/public/index.php'),
        );

        self::assertSame(ResponseStatus::BadRequest->value, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('/srv/app', $body);
        self::assertNoPathSeparatorInText($body);
    }

    /**
     * No pipeline runs before a request object exists, so
     * SecurityHeadersMiddleware never sees this response. It has to carry the
     * restrictive set itself or ship with none at all — which is what the
     * zero-byte HTTP 500 produced by `display_errors=Off` did.
     */
    #[Test]
    public function preRequestErrorCarriesItsOwnSecurityHeaders(): void
    {
        $response = $this->preRequestFailureResponse(
            new Kernel(),
            BodyTooLargeException::exceedsLimit(10_485_761, 10_485_760),
        );

        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("form-action 'none'", $csp);
    }

    private function preRequestFailureResponse(Kernel $kernel, Throwable $e): ResponseInterface
    {
        /** @var ResponseInterface */
        return new ReflectionMethod($kernel, 'preRequestFailureResponse')->invoke($kernel, $e);
    }

    /**
     * The closing tags of the generic page are the only slashes it may contain.
     * Drop the inline stylesheet and the markup, and no separator of either
     * platform may survive in what is left.
     */
    private static function assertNoPathSeparatorInText(string $html): void
    {
        $withoutStyle = (string) preg_replace('#<style\b[^>]*>.*?</style>#s', '', $html);
        $text = strip_tags($withoutStyle);

        self::assertStringNotContainsString('/', $text, 'Rendered error text contains a path separator');
        self::assertStringNotContainsString('\\', $text, 'Rendered error text contains a path separator');
    }
}

/**
 * @internal Middleware that always throws.
 */
final class ThrowingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw new RuntimeException('Middleware failure');
    }
}
