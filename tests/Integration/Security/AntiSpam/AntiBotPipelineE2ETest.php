<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Security\AntiSpam;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Core\Wiring\CacheWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\AntiSpam\PrivacyPass\TokenChallenge;
use Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass\PrivacyPassTokenFactory;

use function base64_encode;
use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rtrim;
use function strtr;
use function sys_get_temp_dir;
use function var_export;

/**
 * End-to-end coverage for the anti-bot stack through the real global middleware
 * pipeline: boot the same wiring the Kernel uses (cache then anti-spam) and
 * dispatch HTTP requests, asserting the wired middleware actually fire — not
 * just that the units work in isolation.
 */
final class AntiBotPipelineE2ETest extends TestCase
{
    #[Test]
    public function declaredAiCrawlerIsBlockedThroughThePipeline(): void
    {
        $pipeline = $this->boot([
            'ai_crawlers' => ['enabled' => true, 'training_action' => 'block'],
        ]);

        $response = $pipeline->dispatch($this->request(['User-Agent' => 'GPTBot/1.0']), $this->ok());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('noai', $response->getHeaderLine('X-Robots-Tag'));
    }

    #[Test]
    public function ordinaryRequestPassesThroughWithTdmReservation(): void
    {
        $pipeline = $this->boot([
            'ai_crawlers' => ['enabled' => true, 'training_action' => 'block'],
        ]);

        $response = $pipeline->dispatch($this->request(['User-Agent' => 'Mozilla/5.0']), $this->ok());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('noai', $response->getHeaderLine('X-Robots-Tag'));
    }

    #[Test]
    public function adaptiveEngineBlocksHighRiskThroughThePipeline(): void
    {
        // Datacenter signal matches any IPv4 with a block-level score.
        $pipeline = $this->boot([
            'adaptive_risk' => ['enabled' => true],
            'datacenter' => ['enabled' => true, 'ranges' => ['0.0.0.0/0'], 'score' => 0.95],
        ]);

        $response = $pipeline->dispatch(
            $this->request([], ['REMOTE_ADDR' => '203.0.113.5']),
            $this->ok(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    #[RequiresPhpExtension('gmp')]
    public function validPrivateAccessTokenBypassesTheEngineThroughThePipeline(): void
    {
        $issued = PrivacyPassTokenFactory::issue(new TokenChallenge(0x0002, 'issuer.example', 'origin.example'));

        $antiSpam = [
            'adaptive_risk' => ['enabled' => true],
            'datacenter' => ['enabled' => true, 'ranges' => ['0.0.0.0/0'], 'score' => 0.95],
            'privacy_pass' => [
                'enabled' => true,
                'issuer_name' => 'issuer.example',
                'origin_info' => 'origin.example',
                'token_key' => $this->base64Url($issued['spkiDer']),
            ],
        ];

        $pipeline = $this->boot($antiSpam);

        // Without a token: the datacenter signal blocks.
        $blocked = $pipeline->dispatch($this->request([], ['REMOTE_ADDR' => '203.0.113.5']), $this->ok());
        self::assertSame(403, $blocked->getStatusCode(), 'high-risk request blocked without a token');

        // With a valid token: the PAT bypass short-circuits scoring to allow.
        $allowed = $pipeline->dispatch(
            $this->request(
                ['Authorization' => 'PrivateToken token="' . $this->base64Url($issued['token']) . '"'],
                ['REMOTE_ADDR' => '203.0.113.5'],
            ),
            $this->ok(),
        );
        self::assertSame(200, $allowed->getStatusCode(), 'a valid Private Access Token bypasses the block');
    }

    /**
     * @param array<string, mixed> $antiSpam
     */
    private function boot(array $antiSpam): MiddlewarePipeline
    {
        $configPath = sys_get_temp_dir() . '/pulsar_antibot_e2e_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/cache.php', '<?php return ["enabled" => true];');
        file_put_contents($configPath . '/anti-spam.php', '<?php return ' . var_export($antiSpam, true) . ';');

        $configManager = new ConfigManager($configPath);
        // Register the anti-spam config loader before load(), as the Kernel does,
        // so AntiSpamConfigSet is built into the repository.
        ConfigLoaderRegistrar::register($configManager, [new AntiSpamWiring()]);
        $configManager->load();

        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $registry = new MiddlewareRegistry();

        // Same order as the Kernel: cache first (binds the tagged cache), then anti-spam.
        new CacheWiring()->wire($container, $configManager, $middleware, $registry, $router);
        new AntiSpamWiring()->wire($container, $configManager, $middleware, $registry, $router);

        return $middleware;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $serverParams
     */
    private function request(array $headers, array $serverParams = []): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/', headers: $headers, serverParams: $serverParams);
    }

    /**
     * @return callable(ServerRequestInterface): ResponseInterface
     */
    private function ok(): callable
    {
        return static fn(ServerRequestInterface $request): ResponseInterface => Response::text('OK');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
