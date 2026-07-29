<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use LogicException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\Router;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerMiddleware;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerVerificationConfig;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivacyPassDirectoryClient;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivacyPassConfig;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivacyPassRefreshKeysCommand;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivateTokenChallengeMiddleware;
use Pulsar\Security\AntiSpam\PrivacyPass\TokenChallenge;
use Pulsar\Security\AntiSpam\Risk\AdaptiveChallengeMiddleware;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskConfig;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskEngine;
use Pulsar\Security\AntiSpam\Risk\DatacenterIpConfig;
use Pulsar\Security\AntiSpam\Risk\Ja4Config;
use Pulsar\Security\AntiSpam\Risk\RiskDecision;
use Pulsar\Security\AntiSpam\Risk\VelocityConfig;
use Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass\PrivacyPassTokenFactory;
use RuntimeException;
use Stringable;

use function base64_encode;
use function bin2hex;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rtrim;
use function str_contains;
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
    public function aiCrawlerIdentityVerifierIsWiredAndBlocksImpersonators(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        // GPTBot allowed by override, but verification has its published range:
        // a forged GPTBot UA from outside that range must be blocked by the
        // wired middleware (proving the verifier is constructed and passed in).
        $this->wire(
            $container,
            $pipeline,
            "'ai_crawlers' => ['enabled' => true, 'overrides' => ['GPTBot' => 'allow']], "
            . "'ai_crawler_verification' => ['enabled' => true, 'ranges' => ['GPTBot' => ['203.0.113.0/24']]]",
        );

        self::assertTrue($container->has(AiCrawlerVerificationConfig::class));

        $middleware = $container->get(AiCrawlerMiddleware::class);
        self::assertInstanceOf(AiCrawlerMiddleware::class, $middleware);

        $forged = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'GPTBot/1.0'],
            serverParams: ['REMOTE_ADDR' => '8.8.8.8'],
        );

        self::assertSame(403, $middleware->process($forged, $this->okHandler())->getStatusCode());
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('OK');
            }
        };
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

    #[Test]
    public function datacenterSignalFeedsTheEngineWhenWired(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        // 0.0.0.0/0 matches any IPv4; a high score forces a block, proving the
        // datacenter provider is wired into the engine.
        $this->wire(
            $container,
            $pipeline,
            "'adaptive_risk' => ['enabled' => true], "
            . "'datacenter' => ['enabled' => true, 'ranges' => ['0.0.0.0/0'], 'score' => 0.95]",
        );

        self::assertTrue($container->has(DatacenterIpConfig::class));

        $engine = $container->get(AdaptiveRiskEngine::class);
        self::assertInstanceOf(AdaptiveRiskEngine::class, $engine);

        $request = new ServerRequest(method: 'GET', uri: '/', serverParams: ['REMOTE_ADDR' => '203.0.113.5']);

        self::assertSame(RiskDecision::Block, $engine->assess($request)->decision);
    }

    #[Test]
    public function velocitySignalFeedsTheEngineWhenWired(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $container->instance(TaggedCacheInterface::class, $this->inMemoryCache());

        $this->wire(
            $container,
            $pipeline,
            "'adaptive_risk' => ['enabled' => true], "
            . "'velocity' => ['enabled' => true, 'threshold' => 1, 'window_seconds' => 300, 'max_score' => 0.95]",
        );

        self::assertTrue($container->has(VelocityConfig::class));

        $engine = $container->get(AdaptiveRiskEngine::class);
        self::assertInstanceOf(AdaptiveRiskEngine::class, $engine);

        $request = new ServerRequest(method: 'GET', uri: '/', serverParams: ['REMOTE_ADDR' => '203.0.113.5']);

        (void) $engine->assess($request); // count 1 (== threshold)
        // count 2 is over threshold => velocity contributes maxScore => block.
        self::assertSame(RiskDecision::Block, $engine->assess($request)->decision);
    }

    #[Test]
    public function duplicateDetectionWithoutCacheLogsSecurityWarning(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $logger = new WarningSpyLogger();
        $container->instance(LoggerInterface::class, $logger);

        // Enabled but no TaggedCacheInterface bound => previously a SILENT no-op.
        $this->wire($container, $pipeline, "'duplicate_detection_enabled' => true, 'reputation_cooldown_enabled' => false");

        self::assertTrue(
            $logger->hasWarningContaining('Duplicate detection'),
            'an enabled cache-dependent check must warn loudly when no cache is bound',
        );
        self::assertTrue($logger->hasWarningContaining('no cache is bound'));
    }

    #[Test]
    public function reputationCooldownWithoutCacheLogsSecurityWarning(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $logger = new WarningSpyLogger();
        $container->instance(LoggerInterface::class, $logger);

        $this->wire($container, $pipeline, "'duplicate_detection_enabled' => false, 'reputation_cooldown_enabled' => true");

        self::assertTrue($logger->hasWarningContaining('Reputation cooldown'));
    }

    #[Test]
    public function velocitySignalWithoutCacheLogsSecurityWarning(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $logger = new WarningSpyLogger();
        $container->instance(LoggerInterface::class, $logger);

        $this->wire(
            $container,
            $pipeline,
            "'duplicate_detection_enabled' => false, 'reputation_cooldown_enabled' => false, "
            . "'adaptive_risk' => ['enabled' => true], 'velocity' => ['enabled' => true]",
        );

        self::assertTrue($logger->hasWarningContaining('Velocity risk signal'));
    }

    #[Test]
    public function cacheDependentFeaturesDoNotWarnWhenCacheIsBound(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $logger = new WarningSpyLogger();
        $container->instance(LoggerInterface::class, $logger);
        $container->instance(TaggedCacheInterface::class, $this->inMemoryCache());

        $this->wire(
            $container,
            $pipeline,
            "'duplicate_detection_enabled' => true, 'reputation_cooldown_enabled' => true, "
            . "'adaptive_risk' => ['enabled' => true], 'velocity' => ['enabled' => true]",
        );

        self::assertFalse(
            $logger->hasWarningContaining('no cache is bound'),
            'no cache-degradation warning when the tagged cache is bound',
        );
    }

    #[Test]
    public function anUncachedMxCheckIsReportedAsACostNotAsAnInertControl(): void
    {
        // The MX check is enabled by default and works without a cache — it just
        // resolves live per submission. Reporting it as an inert security control
        // fired a false warning on every boot of a default, cache-less install.
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $logger = new WarningSpyLogger();
        $container->instance(LoggerInterface::class, $logger);

        $this->wire($container, $pipeline, "'duplicate_detection_enabled' => false, 'reputation_cooldown_enabled' => false");

        self::assertFalse(
            $logger->hasWarningContaining('it is inert'),
            'an uncached MX check is not inert and must not be reported as such',
        );
        self::assertTrue(
            $logger->hasRecordContaining('resolve DNS on every submission'),
            'the real cost of running uncached must still be stated',
        );
    }

    #[Test]
    public function disabledCacheDependentFeaturesDoNotWarn(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $logger = new WarningSpyLogger();
        $container->instance(LoggerInterface::class, $logger);

        // All cache-dependent features off; velocity off (adaptive_risk absent).
        $this->wire($container, $pipeline, "'duplicate_detection_enabled' => false, 'reputation_cooldown_enabled' => false");

        self::assertFalse(
            $logger->hasWarningContaining('no cache is bound'),
            'a disabled feature must not warn about a missing cache',
        );
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
        // AntiSpamConfigSet now loads through the ConfigRepository
        // (ProvidesConfigLoaders), exactly as at boot: register the wiring's loader
        // and load(). load() requires the three mandatory config files.
        file_put_contents($configPath . '/app.php', '<?php return [];');
        file_put_contents($configPath . '/security.php', '<?php return [];');
        file_put_contents($configPath . '/observability.php', '<?php return [];');
        file_put_contents($configPath . '/anti-spam.php', "<?php return [$antiSpamBody];");

        $configManager = new ConfigManager($configPath);
        $wiring = new AntiSpamWiring();
        ConfigLoaderRegistrar::register($configManager, [$wiring]);
        $configManager->load();

        $wiring->wire($container, $configManager, $pipeline, new MiddlewareRegistry(), new Router());
    }
}

/**
 * @internal Test helper: collects warning-level log messages for assertions.
 */
final class WarningSpyLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    /**
     * Every record, at any level — lets a test assert on non-warning output too.
     *
     * @var list<string>
     */
    public array $records = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = (string) $message;

        if ($level === LogLevel::WARNING) {
            $this->warnings[] = (string) $message;
        }
    }

    public function hasRecordContaining(string $needle): bool
    {
        foreach ($this->records as $record) {
            if (str_contains($record, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function hasWarningContaining(string $needle): bool
    {
        foreach ($this->warnings as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }
}
