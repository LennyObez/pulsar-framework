<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerMiddleware;
use Pulsar\Security\AntiSpam\Risk\AdaptiveChallengeMiddleware;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskConfig;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskEngine;
use Pulsar\Security\AntiSpam\Risk\Ja4Config;
use Pulsar\Security\AntiSpam\Risk\RiskDecision;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
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

    private function wire(Container $container, MiddlewarePipeline $pipeline, string $antiSpamBody): void
    {
        $configPath = sys_get_temp_dir() . '/pulsar_antispam_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        file_put_contents($configPath . '/anti-spam.php', "<?php return [$antiSpamBody];");

        $configManager = new ConfigManager($configPath);

        new AntiSpamWiring()->wire($container, $configManager, $pipeline, new MiddlewareRegistry(), new Router());
    }
}
