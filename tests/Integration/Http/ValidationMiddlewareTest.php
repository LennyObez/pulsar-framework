<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Http;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\ValidationMiddleware;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\Validation\Rule\MinLength;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Rule\StringType;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Validator;
use Pulsar\Routing\Route;

#[CoversClass(ValidationMiddleware::class)]
#[CoversClass(Validator::class)]
#[CoversClass(ExceptionHandler::class)]
#[CoversClass(Kernel::class)]
#[CoversClass(MiddlewareRegistry::class)]
final class ValidationMiddlewareTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_val_mw_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);
        $this->writeConfigFiles();

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

    private function writeConfigFiles(): void
    {
        file_put_contents($this->tempDir . '/app.php', "<?php return [
            'name' => 'TestApp',
            'env' => 'local',
            'debug' => true,
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

    private function createKernel(): Kernel
    {
        $configManager = new ConfigManager(configPath: $this->tempDir);
        return new Kernel(configManager: $configManager);
    }

    #[Test]
    public function invalidDataReturns422Json(): void
    {
        $kernel = $this->createKernel();

        // Register route with validation middleware via Route constructor
        $kernel->router()->add(new Route(
            methods: [Method::POST],
            path: '/api/users',
            handler: fn(Request $req) => Response::json(['ok' => true]),
            middleware: [CreateUserValidation::class],
        ));

        $request = new Request(
            method: Method::POST,
            uri: '/api/users',
            path: '/api/users',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '{"name":""}',
        );

        $response = $kernel->handle($request);

        self::assertSame(422, $response->status->value);
        self::assertStringContainsString('application/json', $response->headers->first('Content-Type') ?? '');

        /** @var array{error: string, status: int, violations: list<mixed>} $data */
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Validation Failed', $data['error']);
        self::assertSame(422, $data['status']);
        self::assertNotEmpty($data['violations']);
    }

    #[Test]
    public function validDataReachesHandler(): void
    {
        $kernel = $this->createKernel();

        $kernel->router()->add(new Route(
            methods: [Method::POST],
            path: '/api/users',
            handler: fn(Request $req) => Response::json(['created' => true]),
            middleware: [CreateUserValidation::class],
        ));

        $request = new Request(
            method: Method::POST,
            uri: '/api/users',
            path: '/api/users',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: '{"name":"John Doe","email":"john@example.com"}',
        );

        $response = $kernel->handle($request);

        self::assertSame(200, $response->status->value);

        /** @var array{created: bool} $data */
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['created']);
    }
}

/**
 * @internal
 */
final class CreateUserValidation extends ValidationMiddleware
{
    /**
     * @return array<string, list<RuleInterface>>
     */
    #[Override]
    protected function rules(ServerRequestInterface $request): array
    {
        return [
            'name' => [new Required(), new StringType(), new MinLength(1)],
            'email' => [new Required(), new StringType()],
        ];
    }
}
