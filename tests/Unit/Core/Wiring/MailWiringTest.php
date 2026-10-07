<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\MailConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\MailWiring;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Mail\MailManager;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Mail\Security\PhiScrubber;
use Pulsar\Mail\Security\PhiScrubberInterface;
use Pulsar\Mail\Transport\CurlMailHttpClient;
use Pulsar\Mail\Transport\MailHttpClientInterface;
use Pulsar\Mail\Transport\MailHttpResponse;
use Pulsar\Mail\Transport\Psr18MailHttpClient;
use Pulsar\Mail\Webhook\MailWebhookController;
use Pulsar\Mail\Webhook\WebhookHandlerInterface;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(MailWiring::class)]
final class MailWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersMailServicesWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, hipaaMode: false);
        $configManager->load();

        $wiring = new MailWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(MailConfig::class));
        self::assertTrue($container->has(MailManager::class));
        self::assertTrue($container->has(MailManagerInterface::class));
        self::assertFalse($container->has(PhiScrubber::class));
    }

    #[Test]
    public function wireRegistersPhiScrubberInHipaaMode(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true, hipaaMode: true);
        $configManager->load();

        $wiring = new MailWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(PhiScrubber::class));
        self::assertTrue($container->has(PhiScrubberInterface::class));
    }

    #[Test]
    public function wireSkipsWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false, hipaaMode: false);
        $configManager->load();

        $wiring = new MailWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(MailConfig::class));
        self::assertFalse($container->has(MailManager::class));
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

        $wiring = new MailWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(MailConfig::class));
    }

    #[Test]
    public function wireRegistersWebhookEndpointWhenConfigured(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);

        $configManager = $this->createConfigManagerWithWebhooks();
        $configManager->load();

        new MailWiring()->wire($container, $configManager, $middleware, new MiddlewareRegistry(), $router);

        self::assertTrue($container->has(MailWebhookController::class), 'webhook controller is wired');
        self::assertTrue($container->has(WebhookHandlerInterface::class));

        $matched = $router->match(Method::POST, '/_pulsar/mail/webhook');
        self::assertSame('pulsar.mail.webhook', $matched->route->name);
    }

    #[Test]
    public function wireSkipsWebhookEndpointWhenNotConfigured(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);

        // Mail enabled, but no webhooks block => endpoint stays off (opt-in).
        $configManager = $this->createConfigManager(enabled: true, hipaaMode: false);
        $configManager->load();

        new MailWiring()->wire($container, $configManager, $middleware, new MiddlewareRegistry(), $router);

        self::assertFalse($container->has(MailWebhookController::class));
    }

    #[Test]
    public function wireBindsDefaultCurlHttpClientWhenNoneProvided(): void
    {
        // Acceptance: with no app wiring, an API-capable HTTP client is bound so
        // mailgun/ses/postmark/sendgrid work out of the box (no "required" error).
        $container = new Container();
        $configManager = $this->createConfigManager(enabled: true, hipaaMode: false);
        $configManager->load();

        new MailWiring()->wire($container, $configManager, new MiddlewarePipeline($container), new MiddlewareRegistry(), new Router());

        self::assertTrue($container->has(MailHttpClientInterface::class));
        self::assertInstanceOf(CurlMailHttpClient::class, $container->get(MailHttpClientInterface::class));
    }

    #[Test]
    public function wirePrefersContainerPsr18ClientForMail(): void
    {
        // When the application provides a PSR-18 client, mail reuses it via the
        // adapter instead of the built-in cURL client.
        $container = new Container();
        $container->instance(ClientInterface::class, new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return Response::text('ok');
            }
        });

        $configManager = $this->createConfigManager(enabled: true, hipaaMode: false);
        $configManager->load();

        new MailWiring()->wire($container, $configManager, new MiddlewarePipeline($container), new MiddlewareRegistry(), new Router());

        self::assertInstanceOf(Psr18MailHttpClient::class, $container->get(MailHttpClientInterface::class));
    }

    #[Test]
    public function wirePreservesApplicationProvidedHttpClient(): void
    {
        $appClient = new class implements MailHttpClientInterface {
            public function request(string $method, string $url, array $headers, string $body): MailHttpResponse
            {
                return new MailHttpResponse(200, '');
            }
        };

        $container = new Container();
        $container->instance(MailHttpClientInterface::class, $appClient);

        $configManager = $this->createConfigManager(enabled: true, hipaaMode: false);
        $configManager->load();

        new MailWiring()->wire($container, $configManager, new MiddlewarePipeline($container), new MiddlewareRegistry(), new Router());

        self::assertSame($appClient, $container->get(MailHttpClientInterface::class));
    }

    private function createConfigManagerWithWebhooks(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_mail_wh_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents(
            $configPath . '/mail.php',
            '<?php return ["enabled" => true, "from" => ["address" => "noreply@example.com", "name" => "Test"], '
            . '"webhooks" => ["enabled" => true, "provider" => "postmark", "secret" => "tok-123"]];',
        );

        return new ConfigManager($configPath);
    }

    private function createConfigManager(bool $enabled, bool $hipaaMode): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_mail_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $enabledStr = $enabled ? 'true' : 'false';
        $hipaaStr = $hipaaMode ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/mail.php', '<?php return ["enabled" => ' . $enabledStr . ', "default" => "log", "from" => ["address" => "noreply@example.com", "name" => "Test"], "hipaa_mode" => ' . $hipaaStr . '];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_mail_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
