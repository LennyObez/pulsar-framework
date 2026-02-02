<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\Validation\ValidationException;
use Pulsar\Http\Validation\ValidationResult;
use Pulsar\Http\Validation\Violation;
use RuntimeException;

#[CoversClass(Kernel::class)]
#[CoversClass(ExceptionHandler::class)]
final class KernelErrorHandlingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_kernel_err_test_' . uniqid();
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

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path)) {
                    $this->cleanDir($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($dir);
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
                "default_channel" => "stderr",
                "level" => "debug",
                "channels" => [
                    "stderr" => ["driver" => "stream", "stream" => "php://stderr"],
                ],
            ],
        ];');
    }

    private function createRequest(
        Method $method = Method::GET,
        string $path = '/',
        ?HeaderBag $headers = null,
    ): Request {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: $headers ?? new HeaderBag(),
            body: '',
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

        self::assertSame(ResponseStatus::InternalServerError, $response->status);
    }

    #[Test]
    public function missingRouteReturns404(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/', fn() => Response::text('home'));

        $response = $kernel->handle($this->createRequest(path: '/nonexistent'));

        self::assertSame(ResponseStatus::NotFound, $response->status);
    }

    #[Test]
    public function wrongMethodReturns405(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/test', fn() => Response::text('ok'));

        $response = $kernel->handle($this->createRequest(Method::POST, '/test'));

        self::assertSame(ResponseStatus::MethodNotAllowed, $response->status);
        self::assertNotNull($response->headers->first('Allow'));
    }

    #[Test]
    public function debugModeShowsTrace(): void
    {
        $kernel = $this->createKernel(debug: true);
        $kernel->router()->get('/error', fn() => throw new RuntimeException('Debug visible'));

        $response = $kernel->handle($this->createRequest(path: '/error'));

        self::assertStringContainsString('Debug visible', $response->body);
        self::assertStringContainsString('RuntimeException', $response->body);
    }

    #[Test]
    public function productionModeHidesDetails(): void
    {
        $kernel = $this->createKernel(debug: false);
        $kernel->router()->get('/error', fn() => throw new RuntimeException('Secret error info'));

        $response = $kernel->handle($this->createRequest(path: '/error'));

        self::assertStringNotContainsString('Secret error info', $response->body);
        self::assertStringNotContainsString('RuntimeException', $response->body);
    }

    #[Test]
    public function middlewareExceptionCaught(): void
    {
        $kernel = $this->createKernel();
        $kernel->addMiddleware(new ThrowingMiddleware());
        $kernel->router()->get('/', fn() => Response::text('ok'));

        $response = $kernel->handle($this->createRequest());

        self::assertSame(ResponseStatus::InternalServerError, $response->status);
    }

    #[Test]
    public function httpExceptionUsesCustomStatus(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/forbidden', fn() => throw HttpException::forbidden('No access'));

        $response = $kernel->handle($this->createRequest(path: '/forbidden'));

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    #[Test]
    public function kernelWithoutConfigManagerPropagatesExceptions(): void
    {
        $kernel = new Kernel();
        $kernel->router()->get('/error', fn() => throw new RuntimeException('No handler'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No handler');
        $kernel->handle($this->createRequest(path: '/error'));
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

        self::assertSame(ResponseStatus::UnprocessableEntity, $response->status);
        self::assertStringContainsString('application/json', $response->headers->first('Content-Type') ?? '');

        /** @var array{error: string, status: int, violations: list<mixed>} $data */
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Validation Failed', $data['error']);
        self::assertSame(422, $data['status']);
        self::assertCount(1, $data['violations']);
    }

    #[Test]
    public function acceptJsonHeaderReturnsJsonError(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/error', fn() => throw new RuntimeException('Server error'));

        $headers = new HeaderBag(['Accept' => 'application/json']);
        $response = $kernel->handle($this->createRequest(path: '/error', headers: $headers));

        self::assertSame(ResponseStatus::InternalServerError, $response->status);
        self::assertStringContainsString('application/json', $response->headers->first('Content-Type') ?? '');

        /** @var array{error: string, status: int} $data */
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Internal Server Error', $data['error']);
        self::assertSame(500, $data['status']);
    }

    #[Test]
    public function acceptJsonWith404ReturnsJsonError(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/', fn() => Response::text('home'));

        $headers = new HeaderBag(['Accept' => 'application/json']);
        $response = $kernel->handle($this->createRequest(path: '/nonexistent', headers: $headers));

        self::assertSame(ResponseStatus::NotFound, $response->status);

        /** @var array{error: string, status: int} $data */
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Not Found', $data['error']);
        self::assertSame(404, $data['status']);
    }
}

/**
 * @internal Middleware that always throws.
 */
final class ThrowingMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        throw new RuntimeException('Middleware failure');
    }
}
