<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ZeroTrustWiring;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\ZeroTrust\Middleware\ZeroTrustMiddleware;
use Pulsar\Security\ZeroTrust\Policy\PolicyEngineInterface;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(ZeroTrustWiring::class)]
final class ZeroTrustWiringTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    #[Test]
    public function doesNotWireAnythingWhenDisabled(): void
    {
        [$container, $registry] = $this->wire(''); // no zero_trust block => disabled

        self::assertFalse($container->has(ZeroTrustMiddleware::class));
        self::assertFalse($container->has(PolicyEngineInterface::class));
        self::assertFalse($registry->hasAlias('zerotrust'));
    }

    #[Test]
    public function wiresTheMiddlewareAndAliasWhenEnabled(): void
    {
        [$container, $registry] = $this->wire($this->grantEverythingConfig());

        self::assertTrue($container->has(ZeroTrustMiddleware::class));
        self::assertTrue($container->has(PolicyEngineInterface::class));
        self::assertTrue($registry->hasAlias('zerotrust'));
    }

    #[Test]
    public function enabledMiddlewareGrantsAMatchingRuleAndDeniesUnmatchedRoutes(): void
    {
        [$container] = $this->wire($this->grantEverythingConfig());

        /** @var ZeroTrustMiddleware $middleware */
        $middleware = $container->get(ZeroTrustMiddleware::class);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // A rule grants /public/*; the request matches => pass through (200).
        $granted = $middleware->process(new ServerRequest(method: 'GET', uri: '/public/home'), $handler);
        self::assertSame(200, $granted->getStatusCode());

        // No rule matches /private => deny-by-default (403).
        $denied = $middleware->process(new ServerRequest(method: 'GET', uri: '/private/secret'), $handler);
        self::assertSame(403, $denied->getStatusCode());
    }

    private function grantEverythingConfig(): string
    {
        return "'zero_trust' => ['enabled' => true, 'rules' => ["
            . "['name' => 'public', 'resource_pattern' => '/public/*', 'action' => '*', 'on_match' => 'grant', 'requirements' => []],"
            . ']],';
    }

    /**
     * @return array{0: Container, 1: MiddlewareRegistry}
     */
    private function wire(string $zeroTrustBlock): array
    {
        $container = new Container();
        $container->instance(EventDispatcherInterface::class, $this->createStub(EventDispatcherInterface::class));
        $container->instance(AuditLoggerInterface::class, $this->createStub(AuditLoggerInterface::class));

        $configManager = $this->createConfigManager($zeroTrustBlock);
        $configManager->load();

        $registry = new MiddlewareRegistry();
        new ZeroTrustWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            $registry,
            new Router(),
        );

        return [$container, $registry];
    }

    private function createConfigManager(string $zeroTrustBlock): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_zt_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []], "audit" => ["enabled" => false]];');
        file_put_contents(
            $configPath . '/security.php',
            '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limiting" => [], ' . $zeroTrustBlock . '];',
        );
        $this->tempFiles[] = $configPath . '/app.php';
        $this->tempFiles[] = $configPath . '/observability.php';
        $this->tempFiles[] = $configPath . '/security.php';

        return new ConfigManager($configPath);
    }
}
