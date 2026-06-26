<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use LogicException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\Router;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerMiddleware;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivacyPassDirectoryClient;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivacyPassConfig;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivacyPassRefreshKeysCommand;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivateTokenChallengeMiddleware;
use Pulsar\Security\AntiSpam\PrivacyPass\TokenChallenge;
use Pulsar\Security\AntiSpam\Risk\AdaptiveChallengeMiddleware;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskConfig;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskEngine;
use Pulsar\Security\AntiSpam\Risk\Ja4Config;
use Pulsar\Security\AntiSpam\Risk\RiskDecision;
use Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass\PrivacyPassTokenFactory;
use RuntimeException;

use function base64_encode;
use function bin2hex;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rtrim;
use function strtr;
use function sys_get_temp_dir;

final class AntiSpamWiringTest extends TestCase
{
    #[Test]
    public function pipesAiCrawlerMiddlewareGloballyWhenEnabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire($container, $pipeline, "'ai_crawlers' => ['enabled' => true]");

        self::assertTrue($container->has(AiCrawlerConfig::class));
        self::assertTrue($container->has(AiCrawlerMiddleware::class), 'middleware is bound when enabled');
        self::assertSame($before + 1, $pipeline->count(), 'middleware is piped globally');
    }

    #[Test]
    public function doesNotPipeWhenDisabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        // ai_crawlers absent => disabled (the default).
        $this->wire($container, $pipeline, "'honeypot_enabled' => false");

        self::assertTrue($container->has(AiCrawlerConfig::class), 'config is always bound for introspection');
        self::assertFalse($container->has(AiCrawlerMiddleware::class));
        self::assertSame($before, $pipeline->count(), 'nothing piped when disabled');
    }

    #[Test]
    public function pipesAdaptiveChallengeMiddlewareWhenEnabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire($container, $pipeline, "'adaptive_risk' => ['enabled' => true]");

        self::assertTrue($container->has(AdaptiveRiskConfig::class));
        self::assertTrue($container->has(AdaptiveChallengeMiddleware::class), 'middleware is bound when enabled');
        self::assertSame($before + 1, $pipeline->count(), 'middleware is piped globally');
    }

    #[Test]
    public function ja4SignalProviderFeedsTheEngineWhenEnabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        // Behavioural proof that the JA4 provider is wired INTO the engine:
        // gate disabled so no trusted proxy is needed, denylisted fingerprint
        // pushes the score to the block threshold.
        $this->wire(
            $container,
            $pipeline,
            "'adaptive_risk' => ['enabled' => true], "
            . "'ja4' => ['enabled' => true, 'trusted_proxies_only' => false, "
            . "'known_bad_fingerprints' => ['t13d1516h2_8daaf6152771_b186095e22b6'], 'match_score' => 0.95]",
        );

        self::assertTrue($container->has(Ja4Config::class), 'config is always bound for introspection');

        $engine = $container->get(AdaptiveRiskEngine::class);
        self::assertInstanceOf(AdaptiveRiskEngine::class, $engine);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['X-JA4' => 't13d1516h2_8daaf6152771_b186095e22b6'],
        );

        $assessment = $engine->assess($request);

        self::assertSame(RiskDecision::Block, $assessment->decision, 'denylisted JA4 fingerprint escalates to block');
    }

    #[Test]
    public function ja4ConfigBoundButProviderInertWhenJa4Disabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        // adaptive_risk on, ja4 absent (=> disabled): a JA4 header must be ignored.
        $this->wire($container, $pipeline, "'adaptive_risk' => ['enabled' => true]");

        self::assertTrue($container->has(Ja4Config::class));

        $engine = $container->get(AdaptiveRiskEngine::class);
        self::assertInstanceOf(AdaptiveRiskEngine::class, $engine);

        // The JA4 header must not change the score at all when ja4 is disabled
        // (relative assertion avoids coupling to BotDetector's absolute output).
        $withHeader = $engine->assess(new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['X-JA4' => 't13d1516h2_8daaf6152771_b186095e22b6'],
        ))->score;

        $withoutHeader = $engine->assess(new ServerRequest(method: 'GET', uri: '/'))->score;

        self::assertSame($withoutHeader, $withHeader, 'JA4 header inert when ja4 disabled');
    }

    #[Test]
    public function privacyPassConfigIsAlwaysBoundForIntrospection(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        // adaptive_risk on, privacy_pass absent => no PAT challenge middleware.
        $this->wire($container, $pipeline, "'adaptive_risk' => ['enabled' => true]");

        self::assertTrue($container->has(PrivacyPassConfig::class));
        self::assertFalse(
            $container->has(PrivateTokenChallengeMiddleware::class),
            'no PAT middleware when privacy_pass disabled',
        );
    }

    #[Test]
    #[RequiresPhpExtension('gmp')]
    public function privacyPassTokenBypassesTheEngineWhenWired(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        // Mint a token bound to the stateless (empty-context) challenge the
        // config produces, signed with a key we control.
        $issued = PrivacyPassTokenFactory::issue(new TokenChallenge(0x0002, 'issuer.example', 'origin.example'));
        $key = $this->base64Url($issued['spkiDer']);

        $this->wire(
            $container,
            $pipeline,
            "'adaptive_risk' => ['enabled' => true], "
            . "'privacy_pass' => ['enabled' => true, 'issuer_name' => 'issuer.example', "
            . "'origin_info' => 'origin.example', 'token_key' => '" . $key . "']",
        );

        // adaptive challenge middleware + the outer PAT challenge advertiser.
        self::assertSame($before + 2, $pipeline->count(), 'adaptive + PAT challenge middleware piped');

        $engine = $container->get(AdaptiveRiskEngine::class);
        self::assertInstanceOf(AdaptiveRiskEngine::class, $engine);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['Authorization' => 'PrivateToken token="' . $this->base64Url($issued['token']) . '"'],
        );

        $assessment = $engine->assess($request);

        self::assertSame(RiskDecision::Allow, $assessment->decision, 'a valid PAT bypasses scoring');
        self::assertTrue($assessment->bypassed);
    }

    #[Test]
    #[RequiresPhpExtension('gmp')]
    public function privacyPassUsesKeysDiscoveredFromTheIssuerDirectory(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        $challenge = new TokenChallenge(0x0002, 'issuer.example', 'origin.example');
        $issued = PrivacyPassTokenFactory::issue($challenge);
        $directoryUrl = 'https://issuer.example/.well-known/private-token-issuer-directory';

        // A directory whose only key is the one our token is signed under.
        $directoryJson = (string) json_encode([
            'token-keys' => [['token-type' => 2, 'token-key' => $this->base64Url($issued['spkiDer'])]],
        ]);

        // Bind a cache + HTTP client, then prime the directory cache via a refresh
        // so the wiring (which reads the cache at boot) discovers the key.
        $cache = $this->inMemoryCache();
        $http = $this->httpReturning(200, $directoryJson);
        $container->instance(TaggedCacheInterface::class, $cache);
        $container->instance(HttpClientInterface::class, $http);
        new PrivacyPassDirectoryClient($http, $cache)->refresh($directoryUrl);

        // No static token_key — keys come solely from the directory.
        $this->wire(
            $container,
            $pipeline,
            "'adaptive_risk' => ['enabled' => true], "
            . "'privacy_pass' => ['enabled' => true, 'issuer_name' => 'issuer.example', "
            . "'origin_info' => 'origin.example', 'directory_url' => '" . $directoryUrl . "']",
        );

        self::assertTrue($container->has(PrivacyPassRefreshKeysCommand::class), 'refresh command is registered');

        $engine = $container->get(AdaptiveRiskEngine::class);
        self::assertInstanceOf(AdaptiveRiskEngine::class, $engine);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['Authorization' => 'PrivateToken token="' . $this->base64Url($issued['token']) . '"'],
        );

        $assessment = $engine->assess($request);

        self::assertSame(RiskDecision::Allow, $assessment->decision, 'a token under a directory-discovered key bypasses');
        self::assertTrue($assessment->bypassed);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function inMemoryCache(): TaggedCacheInterface
    {
        return new class implements TaggedCacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function invalidateTag(string $tag): void {}

            public function invalidateTags(array $tags): void {}
        };
    }

    private function httpReturning(int $status, string $body): HttpClientInterface
    {
        return new class ($status, $body) implements HttpClientInterface {
            public function __construct(
                private readonly int $status,
                private readonly string $body,
            ) {}

            public function get(string $url, array $options = []): HttpResponse
            {
                return new HttpResponse(ResponseStatus::from($this->status), new HeaderBag(), $this->body);
            }

            public function post(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function put(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function patch(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function delete(string $url, array $options = []): HttpResponse
            {
                throw new RuntimeException('unused');
            }

            public function head(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }

            public function options(string $url, array $options = []): HttpResponse
            {
                throw new LogicException('unused');
            }
        };
    }

    private function wire(Container $container, MiddlewarePipeline $pipeline, string $antiSpamBody): void
    {
        $configPath = sys_get_temp_dir() . '/pulsar_antispam_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        file_put_contents($configPath . '/anti-spam.php', "<?php return [$antiSpamBody];");

        $configManager = new ConfigManager($configPath);

        new AntiSpamWiring()->wire($container, $configManager, $pipeline, new MiddlewareRegistry(), new Router());
    }
}
