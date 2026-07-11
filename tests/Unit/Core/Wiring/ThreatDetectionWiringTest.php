<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ThreatDetectionWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\ThreatDetection\HoneypotMiddleware;
use Pulsar\Security\ThreatDetection\ThreatDetectionEngine;
use Pulsar\Security\ThreatDetection\ThreatDetectionMiddleware;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

final class ThreatDetectionWiringTest extends TestCase
{
    #[Test]
    public function buildsEngineAndPipesMiddlewareWhenEnabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire($container, $pipeline, "<?php return ['threat_detection' => ['enabled' => true]];");

        self::assertTrue($container->has(ThreatDetectionEngine::class), 'engine is built');
        self::assertTrue($container->has(ThreatDetectionMiddleware::class), 'middleware is bound');
        self::assertSame($before + 1, $pipeline->count(), 'middleware is piped globally');
    }

    #[Test]
    public function isNoOpWhenDisabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire($container, $pipeline, "<?php return ['threat_detection' => ['enabled' => false]];");

        self::assertFalse($container->has(ThreatDetectionEngine::class));
        self::assertSame($before, $pipeline->count(), 'nothing piped when disabled');
    }

    #[Test]
    public function isNoOpWhenSectionAbsent(): void
    {
        // security.php without a threat_detection section: the feature stays dormant.
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire($container, $pipeline, "<?php return ['csrf' => ['enabled' => true]];");

        self::assertFalse($container->has(ThreatDetectionEngine::class));
        self::assertSame($before, $pipeline->count());
    }

    #[Test]
    public function pipesTheHoneypotWhenItsToggleIsEnabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire(
            $container,
            $pipeline,
            "<?php return ['threat_detection' => ['enabled' => true, 'honeypot' => ['enabled' => true]]];",
        );

        self::assertTrue($container->has(HoneypotMiddleware::class), 'honeypot middleware is bound');
        self::assertSame($before + 2, $pipeline->count(), 'threat-detection + honeypot are piped');
    }

    #[Test]
    public function honeypotStaysDormantWithoutItsToggle(): void
    {
        // threat_detection on, honeypot absent/off: only the engine middleware pipes.
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire($container, $pipeline, "<?php return ['threat_detection' => ['enabled' => true]];");

        self::assertFalse($container->has(HoneypotMiddleware::class));
        self::assertSame($before + 1, $pipeline->count());
    }

    private function wire(Container $container, MiddlewarePipeline $pipeline, string $securityPhp): void
    {
        $configPath = sys_get_temp_dir() . '/pulsar_threat_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        file_put_contents($configPath . '/security.php', $securityPhp);

        $configManager = new ConfigManager($configPath);

        new ThreatDetectionWiring()->wire($container, $configManager, $pipeline, new MiddlewareRegistry(), new Router());
    }
}
