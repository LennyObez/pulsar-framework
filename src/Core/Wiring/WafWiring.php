<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Waf\OwaspCoreRuleSet;
use Pulsar\Security\Waf\ResponseFactoryInterface as WafResponseFactoryInterface;
use Pulsar\Security\Waf\WafConfig;
use Pulsar\Security\Waf\WafEngine;
use Pulsar\Security\Waf\WafMiddleware;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Reads the `waf` section of config/security.php and puts the firewall in front of
 * every request.
 *
 * The section was declared, listed in SecurityConfig::KNOWN_KEYS so the loader
 * accepted it without complaint, and read by nothing. Its only consumer was
 * CmsSecurityIntegration, which hard-codes `new WafConfig(enabled: true,
 * paranoiaLevel: 1)` — so `waf.enabled = false` did not disable the firewall,
 * `paranoia_level = 4` did not raise it, and `bypass_ips` was ignored outright.
 * An application not running the CMS extension had no firewall at all while
 * believing it had configured one.
 *
 * The section is read raw here rather than modelled on SecurityConfig, which is
 * the arrangement that DTO documents for `waf`, `tokenization`, `key_overrides`
 * and `threat_detection`: each is read by the wiring that owns it.
 */
#[Internal]
final readonly class WafWiring implements ServiceWiringInterface, DescribesWiring
{
    public function describeWiring(): WiringContract
    {
        return new WiringContract(
            component: 'waf',
            configClass: WafConfig::class,
            configFile: 'security.php',
            provides: [
                WafEngine::class,
                WafMiddleware::class,
            ],
        );
    }

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $section = $this->loadSection($configManager);

        if ($section === null) {
            return;
        }

        $config = WafConfig::fromArray($section);

        // An operator who wrote `enabled => false` gets no firewall, which is the
        // whole point of reading the section: the previous behaviour ran it anyway.
        if (!$config->enabled) {
            return;
        }

        $engine = new WafEngine($config);

        // Without rules the engine inspects nothing and every request passes, which
        // would be a firewall in name only. The engine filters the set by the
        // configured paranoia level.
        $engine->loadRules(OwaspCoreRuleSet::rules());

        $container->instance(WafConfig::class, $config);
        $container->instance(WafEngine::class, $engine);

        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : new NullLogger();

        /** @var LoggerInterface $logger */
        $wafMiddleware = new WafMiddleware($engine, $logger, $this->responseFactory());

        $container->instance(WafMiddleware::class, $wafMiddleware);
        $middleware->pipe($wafMiddleware);
    }

    /**
     * The WAF declares its own response-factory interface rather than depending on
     * PSR-17. That indirection buys nothing — WafMiddleware already imports
     * Psr\Http\Message\ResponseInterface — but removing it is a break on an #[Api]
     * surface and belongs in its own change, so the adapter lives here for now.
     */
    private function responseFactory(): WafResponseFactoryInterface
    {
        return new class implements WafResponseFactoryInterface {
            public function createResponse(int $statusCode, string $reasonPhrase = ''): ResponseInterface
            {
                return Response::text($reasonPhrase !== '' ? $reasonPhrase : 'Forbidden', $statusCode);
            }
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSection(ConfigManager $configManager): ?array
    {
        $configPath = $configManager->configPath();

        if ($configPath === null) {
            return null;
        }

        $file = $configPath . DIRECTORY_SEPARATOR . 'security.php';

        if (!is_file($file)) {
            return null;
        }

        /** @var mixed $data */
        $data = require $file;

        if (!is_array($data) || !isset($data['waf']) || !is_array($data['waf'])) {
            return null;
        }

        /** @var array<string, mixed> $section */
        $section = $data['waf'];

        return $section;
    }
}
