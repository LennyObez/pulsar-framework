<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceConfig;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Config\CallableConfigLoader;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function array_map;
use function implode;
use function sprintf;

/**
 * Turns config/compliance.php from a declaration into enforcement.
 *
 * The enabled frameworks are resolved into the strictest {@see ComplianceProfile}
 * (registered in the container for any component that wants to read it), and each
 * security-relevant control the profile covers is compared against the operator's
 * configuration. Compliance never loosens a setting; when the operator's value is
 * weaker than the active profile requires:
 *
 *  - strictMode = false (default): the effective config is tightened to the
 *    compliant value in the ConfigRepository and container before any consumer
 *    reads it, and a boot warning names what changed.
 *  - strictMode = true: the boot is refused with a {@see ConfigException} naming
 *    the control, the offending value, and the required value (fail-closed).
 *
 * Enforcement is a no-op when no framework is enabled — a deployment that opts out
 * of compliance is never surprised by the resolver's baseline defaults. This
 * wiring runs after config load and logging but before SecurityWiring/AuthWiring,
 * so the tightened config is the one those subsystems build their services from.
 */
#[Internal(reason: 'Composition root wiring')]
final readonly class ComplianceWiring implements ServiceWiringInterface, ProvidesConfigLoaders
{
    public function configLoaders(): array
    {
        return [
            'compliance' => new CallableConfigLoader(
                ComplianceConfig::class,
                static fn(array $data): object => ComplianceConfig::fromArray($data),
            ),
        ];
    }

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        $config = $repository->has(ComplianceConfig::class)
            ? $repository->get(ComplianceConfig::class)
            : new ComplianceConfig();

        /** @var ComplianceConfig $config */
        $profile = new ComplianceProfileResolver()->resolve($config->enabledFrameworks);
        $container->instance(ComplianceProfile::class, $profile);

        // A deployment that enabled no framework opted out of compliance; do not
        // tighten anything toward the resolver's baseline defaults.
        if ($config->enabledFrameworks === []) {
            return;
        }

        $this->enforceSessionIdleTimeout($container, $repository, $profile, $config->strictMode);
    }

    /**
     * Session idle timeout: a smaller positive value is stricter; 0 disables the
     * check entirely and is therefore the weakest possible setting. The operator's
     * config is compliant only when it enforces a timeout no longer than the
     * profile requires.
     */
    private function enforceSessionIdleTimeout(
        ContainerInterface $container,
        ConfigRepository $repository,
        ComplianceProfile $profile,
        bool $strictMode,
    ): void {
        $required = $profile->sessionIdleTimeout;

        if ($required <= 0 || !$repository->has(SecurityConfig::class)) {
            return;
        }

        /** @var SecurityConfig $security */
        $security = $repository->get(SecurityConfig::class);
        $current = $security->session->idleTimeout;

        if ($current > 0 && $current <= $required) {
            return; // operator already at least as strict
        }

        if ($strictMode) {
            throw ConfigException::complianceViolation(
                'session idle timeout (config/security.php session.idle_timeout)',
                $current === 0 ? 'disabled (0)' : $current . ' s',
                $required . ' s or less',
                $this->frameworkLabels($profile),
            );
        }

        $tightenedSession = $security->session->withIdleTimeout($required);
        $tightenedSecurity = $security->withSession($tightenedSession);

        $repository->set($tightenedSecurity);
        $container->instance(SecurityConfig::class, $tightenedSecurity);
        $container->instance(SessionConfig::class, $tightenedSession);

        $this->warn($container, sprintf(
            'compliance: session idle timeout tightened from %s to %d s to satisfy the active '
            . 'compliance profile (frameworks: %s).',
            $current === 0 ? 'disabled' : $current . ' s',
            $required,
            implode(', ', $this->frameworkLabels($profile)),
        ));
    }

    /**
     * @return list<string>
     */
    private function frameworkLabels(ComplianceProfile $profile): array
    {
        return array_map(
            static fn(ComplianceFramework $f): string => $f->value,
            $profile->enabledFrameworks,
        );
    }

    /**
     * Emit an operator-facing boot warning when a logger is bound. A no-op
     * otherwise — this wiring runs after LoggingWiring, so one normally is.
     */
    private function warn(ContainerInterface $container, string $message): void
    {
        if (!$container->has(LoggerInterface::class)) {
            return;
        }

        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);
        $logger->warning($message);
    }
}
