<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\WafWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Waf\WafConfig;
use Pulsar\Security\Waf\WafEngine;
use Pulsar\Security\Waf\WafMiddleware;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * The `waf` section of config/security.php was read by nothing. It appeared in
 * SecurityConfig::KNOWN_KEYS, so the loader accepted it silently, and its only
 * consumer — CmsSecurityIntegration — hard-coded `new WafConfig(enabled: true,
 * paranoiaLevel: 1)`. Every setting an operator wrote was inert: disabling the
 * firewall did not disable it, raising the paranoia level did not raise it, and
 * an application without the CMS extension had no firewall at all.
 *
 * These cases assert the settings actually take effect, not merely that objects
 * were built.
 */
#[CoversClass(WafWiring::class)]
final class WafWiringTest extends TestCase
{
    #[Test]
    public function anEnabledFirewallIsPipedWithItsRulesLoaded(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        $this->wire($container, $pipeline, <<<'PHP_CONFIG'
            <?php return ['waf' => ['enabled' => true, 'paranoia_level' => 3]];
            PHP_CONFIG);

        self::assertTrue($container->has(WafMiddleware::class));

        $piped = array_filter(
            $pipeline->snapshot(),
            static fn(mixed $entry): bool => $entry instanceof WafMiddleware,
        );

        self::assertCount(1, $piped, 'the firewall must be in the request pipeline, not only in the container');
        self::assertTrue($container->has(WafEngine::class), 'an engine without rules would inspect nothing');
    }

    /**
     * The setting that was most obviously inert: an operator turning the firewall
     * off got one anyway, because nothing read the value.
     */
    #[Test]
    public function disablingTheFirewallInConfigurationActuallyDisablesIt(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        $this->wire($container, $pipeline, <<<'PHP_CONFIG'
            <?php return ['waf' => ['enabled' => false]];
            PHP_CONFIG);

        self::assertFalse($container->has(WafMiddleware::class));
        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function theConfiguredParanoiaLevelReachesTheEngine(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        $this->wire($container, $pipeline, <<<'PHP_CONFIG'
            <?php return ['waf' => ['enabled' => true, 'paranoia_level' => 4, 'bypass_ips' => ['10.0.0.1']]];
            PHP_CONFIG);

        /** @var WafConfig $config */
        $config = $container->get(WafConfig::class);

        self::assertSame(4, $config->paranoiaLevel, 'the operator asked for 4; the hard-coded value was 1');
        self::assertSame(['10.0.0.1'], $config->bypassIps);
    }

    #[Test]
    public function noWafSectionWiresNothing(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        $this->wire($container, $pipeline, <<<'PHP_CONFIG'
            <?php return ['csrf' => []];
            PHP_CONFIG);

        self::assertFalse($container->has(WafMiddleware::class));
        self::assertTrue($pipeline->isEmpty());
    }

    private function wire(Container $container, MiddlewarePipeline $pipeline, string $securityPhp): void
    {
        $configPath = sys_get_temp_dir() . '/pulsar_waf_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        file_put_contents($configPath . '/security.php', $securityPhp);

        new WafWiring()->wire(
            $container,
            new ConfigManager($configPath),
            $pipeline,
            new MiddlewareRegistry(),
            new Router(),
        );
    }
}
