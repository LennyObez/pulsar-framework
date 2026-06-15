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
use Pulsar\View\Command\PlaygroundServeCommand;
use Pulsar\View\Command\ViewCompileCommand;
use Pulsar\View\Directive\DirectiveRegistry;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\Engine\TemplateEngine;
use Pulsar\View\Engine\TemplateEngineInterface;
use Pulsar\View\Engine\TemplateInheritance;
use Pulsar\View\Escaping\AttributeEscaper;
use Pulsar\View\Escaping\CssEscaper;
use Pulsar\View\Escaping\EscaperInterface;
use Pulsar\View\Escaping\HtmlEscaper;
use Pulsar\View\Escaping\JsEscaper;
use Pulsar\View\Escaping\UrlEscaper;
use Pulsar\View\Sandbox\SandboxConfig;
use Pulsar\View\Sandbox\SandboxEngine;
use Pulsar\View\ViewConfig;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(ViewWiring::class)]
final class ViewWiringTest extends TestCase
{
    protected function tearDown(): void
    {
        // ViewWiring::wire() installs a TemplateEngine into the static
        // Response::$templateEngine slot. Without this reset the engine
        // (bound to a now-deleted temp template dir) leaks into later tests
        // — notably the CMS ContentController tests, which then fail to
        // render cms:: templates instead of falling back to inline HTML.
        Response::clearTemplateEngine();
    }

    #[Test]
    public function wireRegistersViewServices(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new ViewWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(ViewConfig::class));
        self::assertTrue($container->has(TemplateCache::class));
        self::assertTrue($container->has(TemplateCompiler::class));
        self::assertTrue($container->has(DirectiveRegistry::class));
        self::assertTrue($container->has(TemplateInheritance::class));
        self::assertTrue($container->has(TemplateEngineInterface::class));
        self::assertTrue($container->has(TemplateEngine::class));

        // Escapers
        self::assertTrue($container->has(EscaperInterface::class));
        self::assertTrue($container->has(HtmlEscaper::class));
        self::assertTrue($container->has(UrlEscaper::class));
        self::assertTrue($container->has(AttributeEscaper::class));
        self::assertTrue($container->has(JsEscaper::class));
        self::assertTrue($container->has(CssEscaper::class));

        // Sandbox
        self::assertTrue($container->has(SandboxConfig::class));
        self::assertTrue($container->has(SandboxEngine::class));

        // CLI commands
        self::assertTrue($container->has(ViewCompileCommand::class));
        self::assertTrue($container->has(PlaygroundServeCommand::class));
    }

    #[Test]
    public function wireSkipsWhenNoConfig(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManagerWithout();
        $configManager->load();

        $wiring = new ViewWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(ViewConfig::class));
    }

    private function createConfigManager(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_view_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $cachePath = sys_get_temp_dir() . '/pulsar_view_cache_' . bin2hex(random_bytes(4));
        @mkdir($cachePath, 0o755, true);

        $templatePath = sys_get_temp_dir() . '/pulsar_view_templates_' . bin2hex(random_bytes(4));
        @mkdir($templatePath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/view.php', '<?php return ["template_paths" => ["' . addslashes($templatePath) . '"], "cache_path" => "' . addslashes($cachePath) . '"];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_view_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
