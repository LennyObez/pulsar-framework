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
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\Validation\ValidationException;
use Pulsar\Http\Validation\ValidationResult;
use Pulsar\Http\Validation\Violation;
use RuntimeException;

use function strlen;

#[CoversClass(Kernel::class)]
#[CoversClass(ExceptionHandler::class)]
final class KernelErrorHandlingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // F3.3: cwd-rooted temp dir so SafePath cleanup helper accepts the path.
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->tempDir = $cwd . '/var/tmp_pulsar_kernel_err_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);
        $this->writeConfigFiles(debug: true);

        putenv('APP_NAME');
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('LOG_LEVEL');
        putenv('LOG_CHANNEL');
    }

    protected function tearDown(): void
    {
        putenv('APP_NAME');
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('LOG_LEVEL');
        putenv('LOG_CHANNEL');

        $this->cleanDir($this->tempDir);
    }

    /**
     * F3.3: route cleanup through SafeFilesystem so the test fixture
     * does not depend on bare `unlink()` (static-analysis flag) and
     * benefits from the same path-traversal guards as production.
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

    /**
     * F4.11: previously the kernel re-threw uncaught exceptions when
     * no ExceptionHandler was registered, leaking stack traces to the
     * SAPI default error page. The fallback now renders a generic
     * 500 via ProductionRenderer so the response is leak-free.
     */
    #[Test]
    public function kernelWithoutConfigManagerReturns500FallbackResponse(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/error', fn() => throw new RuntimeException('No handler'));

        $response = $kernel->handle($this->createRequest(path: '/error'));

        self::assertSame(ResponseStatus::InternalServerError->value, $response->getStatusCode());
        $body = (string) $response->getBody();
        // Generic fallback page — no leak of the exception class /
        // message / stack trace.
        self::assertStringNotContainsString('No handler', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
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
