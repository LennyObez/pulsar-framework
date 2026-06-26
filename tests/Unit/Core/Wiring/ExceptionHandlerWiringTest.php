<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ExceptionHandlerWiring;
use Pulsar\ErrorHandling\ErrorPageRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\ExceptionRendererInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\Router;
use Pulsar\View\Engine\CompiledTemplate;
use Pulsar\View\Engine\TemplateEngineInterface;
use RuntimeException;

#[CoversClass(ExceptionHandlerWiring::class)]
final class ExceptionHandlerWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersExceptionHandlerInDebugMode(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(debug: true);
        $configManager->load();

        $wiring = new ExceptionHandlerWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(ExceptionHandler::class));
    }

    #[Test]
    public function wireRegistersExceptionHandlerInProductionMode(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(debug: false);
        $configManager->load();

        $wiring = new ExceptionHandlerWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(ExceptionHandler::class));
    }

    #[Test]
    public function wireRegistersErrorPageRendererAsRendererInterface(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(debug: false);
        $configManager->load();

        $wiring = new ExceptionHandlerWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(ExceptionRendererInterface::class));
        self::assertInstanceOf(ErrorPageRenderer::class, $container->get(ExceptionRendererInterface::class));
    }

    #[Test]
    public function wireRegistersErrorPageRendererInDebugMode(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(debug: true);
        $configManager->load();

        $wiring = new ExceptionHandlerWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(ExceptionRendererInterface::class));
        self::assertInstanceOf(ErrorPageRenderer::class, $container->get(ExceptionRendererInterface::class));
    }

    #[Test]
    public function errorPageRendererResolvesTemplateEngineBoundAfterWiring(): void
    {
        // Regression: ExceptionHandlerWiring runs BEFORE ViewWiring binds the
        // template engine. The renderer must resolve the engine lazily at render
        // time so project error templates render instead of the inline fallback.
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(debug: false);
        $configManager->load();

        $wiring = new ExceptionHandlerWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        // ViewWiring runs AFTER this wiring and binds the engine.
        $engine = new class implements TemplateEngineInterface {
            #[Override]
            public function render(string $template, array $data = []): string
            {
                return '<html>project ' . $template . '</html>';
            }

            #[Override]
            public function compile(string $template): CompiledTemplate
            {
                return new CompiledTemplate($template, hash('sha256', $template), 0);
            }

            #[Override]
            public function exists(string $template): bool
            {
                return $template === 'errors.404';
            }
        };
        $container->instance(TemplateEngineInterface::class, $engine);

        /** @var ExceptionRendererInterface $renderer */
        $renderer = $container->get(ExceptionRendererInterface::class);
        $html = $renderer->render(
            new RuntimeException('missing'),
            new ServerRequest(method: 'GET', uri: '/missing'),
            ResponseStatus::NotFound,
        );

        self::assertSame('<html>project errors.404</html>', $html);
    }

    private function createConfigManager(bool $debug): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_exc_handler_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $debugStr = $debug ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => ' . $debugStr . ', "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
