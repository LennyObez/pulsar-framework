<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;

use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Full-chain regression lock for RFC 9110 §9.3.2: HEAD must be served wherever
 * GET is, through the whole kernel dispatch -- for BOTH route registration
 * styles. This is the test that would have caught the production sites
 * answering 405 to HEAD on routes registered via the explicit constructor.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(Route::class)]
#[CoversClass(Router::class)]
final class HeadRequestDispatchTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_head_dispatch_' . uniqid();
        mkdir($this->tempDir, 0o775, true);
        $this->writeConfigFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tempDir);
    }

    #[Test]
    public function headDispatchesThroughTheKernelForBothRegistrationStyles(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->get('/sugar', fn() => Response::html('<p>sugar page</p>'));
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/explicit',
            handler: fn() => Response::html('<p>contact page</p>'),
        ));

        $sugarHead = $kernel->handle(new ServerRequest(method: 'HEAD', uri: '/sugar'));
        $explicitHead = $kernel->handle(new ServerRequest(method: 'HEAD', uri: '/explicit'));

        self::assertSame(200, $sugarHead->getStatusCode());
        self::assertSame(200, $explicitHead->getStatusCode());
    }

    #[Test]
    public function headCarriesTheSameHeadersAsTheEquivalentGet(): void
    {
        // The body is stripped at emit time (SAPI layer); at the kernel layer a
        // HEAD response must be indistinguishable from GET header-wise.
        $kernel = $this->createKernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/page',
            handler: fn() => Response::html('<p>hello</p>'),
        ));

        $get = $kernel->handle(new ServerRequest(method: 'GET', uri: '/page'));
        $head = $kernel->handle(new ServerRequest(method: 'HEAD', uri: '/page'));

        self::assertSame(200, $head->getStatusCode());
        self::assertSame($get->getHeaderLine('Content-Type'), $head->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function headOnAPostOnlyRouteStillReturns405(): void
    {
        $kernel = $this->createKernel();
        $kernel->router()->add(new Route(
            methods: [Method::POST],
            path: '/submit',
            handler: fn() => Response::json(['ok' => true]),
        ));

        $response = $kernel->handle(new ServerRequest(method: 'HEAD', uri: '/submit'));

        self::assertSame(405, $response->getStatusCode());
    }

    private function createKernel(): Kernel
    {
        return new Kernel(configManager: new ConfigManager(configPath: $this->tempDir));
    }

    private function writeConfigFiles(): void
    {
        file_put_contents($this->tempDir . '/app.php', "<?php return [
            'name' => 'TestApp',
            'env' => 'local',
            'debug' => false,
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
}
