<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerMiddleware;
use Pulsar\Security\AntiSpam\Risk\AdaptiveChallengeMiddleware;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskConfig;

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

    private function wire(Container $container, MiddlewarePipeline $pipeline, string $antiSpamBody): void
    {
        $configPath = sys_get_temp_dir() . '/pulsar_antispam_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        file_put_contents($configPath . '/anti-spam.php', "<?php return [$antiSpamBody];");

        $configManager = new ConfigManager($configPath);

        new AntiSpamWiring()->wire($container, $configManager, $pipeline, new MiddlewareRegistry(), new Router());
    }
}
