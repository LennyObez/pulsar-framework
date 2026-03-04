<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ViewWiring;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use RuntimeException;

use function addslashes;
use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(ViewWiring::class)]
final class ViewWiringResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up static state after each test
        Response::clearTemplateEngine();
    }

    #[Test]
    public function responseViewDoesNotThrowAfterViewWiring(): void
    {
        // Arrange
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $templatePath = sys_get_temp_dir() . '/pulsar_vw_resp_tpl_' . bin2hex(random_bytes(4));
        @mkdir($templatePath, 0o755, true);

        // Create a minimal template file
        file_put_contents($templatePath . '/hello.pulse.php', 'Hello, <?= $name ?>!');

        $configManager = $this->createConfigManager($templatePath);
        $configManager->load();

        // Clear any previous engine to guarantee clean state
        Response::clearTemplateEngine();

        // Act
        $wiring = new ViewWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // Assert: calling Response::view() with a template should not throw
        // "No TemplateEngineInterface configured"
        $response = Response::view('hello', ['name' => 'World']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hello, World!', (string) $response->getBody());
    }

    #[Test]
    public function responseViewThrowsWithoutViewWiring(): void
    {
        // Arrange: clear any engine
        Response::clearTemplateEngine();

        // Act: attempt to render without an engine configured
        $threw = false;
        $message = '';
        try {
            $response = Response::view('nonexistent');
            // Use $response to satisfy NoDiscard
            $response->getStatusCode();
        } catch (RuntimeException $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        // Assert
        self::assertTrue($threw, 'Response::view() must throw when no engine is configured');
        self::assertStringContainsString(
            'No TemplateEngineInterface has been configured',
            $message,
        );
    }

    #[Test]
    public function viewWiringRegistersTemplateEngineInContainer(): void
    {
        // Arrange
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $templatePath = sys_get_temp_dir() . '/pulsar_vw_resp_eng_' . bin2hex(random_bytes(4));
        @mkdir($templatePath, 0o755, true);

        $configManager = $this->createConfigManager($templatePath);
        $configManager->load();

        // Act
        $wiring = new ViewWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // Assert
        self::assertTrue($container->has(\Pulsar\View\Engine\TemplateEngineInterface::class));
        self::assertTrue($container->has(\Pulsar\View\Engine\TemplateEngine::class));
    }

    private function createConfigManager(string $templatePath): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_vw_resp_cfg_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $cachePath = sys_get_temp_dir() . '/pulsar_vw_resp_cache_' . bin2hex(random_bytes(4));
        @mkdir($cachePath, 0o755, true);

        file_put_contents(
            $configPath . '/app.php',
            '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];',
        );
        file_put_contents(
            $configPath . '/observability.php',
            '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];',
        );
        file_put_contents(
            $configPath . '/security.php',
            '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];',
        );
        file_put_contents(
            $configPath . '/view.php',
            '<?php return ["template_paths" => ["' . addslashes($templatePath) . '"], "cache_path" => "' . addslashes($cachePath) . '"];',
        );

        return new ConfigManager($configPath);
    }
}
