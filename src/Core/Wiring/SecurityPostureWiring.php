<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\SecurityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\WiringContractInspector;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Routing\Router;
use Pulsar\Security\Posture\SecurityPostureCheck;
use Pulsar\Security\Posture\SecurityPostureConfig;
use Pulsar\Security\Posture\SecurityPostureException;
use Pulsar\Security\Posture\SecurityPostureHealthCheck;
use Pulsar\Security\Posture\SecurityPostureReport;
use Pulsar\Security\Posture\SecurityPostureStatus;

use function array_filter;
use function array_values;
use function count;
use function sprintf;

/**
 * Runs the production security-posture preflight at the end of boot.
 *
 * Placed last so the degraded-feature detector sees the fully wired container:
 * a security control left inert by a missing binding (e.g. the captcha
 * single-use replay cache when TaggedCacheInterface is unbound) is reported as
 * a FAIL rather than failing silently. The report is bound for `security:check`
 * and the health endpoint. When enforcement is enabled (opt-in via
 * PULSAR_SECURITY_POSTURE_ENFORCE) and running in production, blocking items
 * abort boot loudly instead of letting the app start in a weakened state.
 */
#[Internal]
final readonly class SecurityPostureWiring implements ServiceWiringInterface
{
    #[Override]
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $environment = $configManager->environment();
        $repository = $configManager->repository();

        if (!$repository->has(SecurityConfig::class)) {
            return;
        }

        $securityConfig = $repository->get(SecurityConfig::class);
        $isProduction = ($environment->get('APP_ENV') ?? 'local') === 'production';
        $debugMode = $repository->has(AppConfig::class) && $repository->get(AppConfig::class)->debug;
        $masterKey = $environment->get('PULSAR_MASTER_KEY');

        // Security features left inert by a missing binding, against the fully
        // wired container (the wiring-contract detector from REQ5/REQ6).
        $contracts = [];
        foreach (WiringList::default() as $wiring) {
            if ($wiring instanceof DescribesWiring) {
                $contracts[] = $wiring->describeWiring();
            }
        }
        $degradedSecurity = array_values(array_filter(
            new WiringContractInspector($container)->degradedFeatures($contracts),
            static fn($feature): bool => $feature->security,
        ));

        $check = new SecurityPostureCheck($securityConfig, $isProduction, $debugMode, $masterKey, $degradedSecurity);
        $report = $check->evaluate();
        $postureConfig = SecurityPostureConfig::fromEnvironment($environment);

        $container->instance(SecurityPostureCheck::class, $check);
        $container->instance(SecurityPostureReport::class, $report);
        $container->instance(SecurityPostureConfig::class, $postureConfig);

        // Surface through the standard health endpoint / health:check.
        if ($container->has(HealthCheckRunnerInterface::class)) {
            /** @var HealthCheckRunnerInterface $healthRunner */
            $healthRunner = $container->get(HealthCheckRunnerInterface::class);
            $healthRunner->register(new SecurityPostureHealthCheck($report));
        }

        $blocking = array_values(array_filter(
            $report->items,
            static fn($item): bool => $postureConfig->blocks($item->status),
        ));

        // Enforce: in production, with enforcement enabled, abort boot loudly.
        if ($postureConfig->enforce && $isProduction && $blocking !== []) {
            throw SecurityPostureException::blocked($blocking);
        }

        $this->log($container, $report);
    }

    private function log(ContainerInterface $container, SecurityPostureReport $report): void
    {
        if (!$container->has(LoggerInterface::class)) {
            return;
        }

        $status = $report->overallStatus();
        if ($status === SecurityPostureStatus::Ok) {
            return;
        }

        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);

        $message = sprintf(
            'Security posture: %d failing, %d degraded — run `security:check` for detail',
            count($report->failures()),
            count($report->degraded()),
        );
        $context = ['category' => 'security', 'posture' => $status->value];

        if ($report->hasFailures()) {
            $logger->error($message, $context);

            return;
        }

        $logger->warning($message, $context);
    }
}
