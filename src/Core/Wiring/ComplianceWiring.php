<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceConfig;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Config\AppConfig;
use Pulsar\Config\AuthConfig;
use Pulsar\Config\CallableConfigLoader;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Config\TwoFactorConfig;
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
        $resolver = new ComplianceProfileResolver();
        $profile = $resolver->resolve($config->enabledFrameworks);
        $container->instance(ComplianceProfile::class, $profile);

        // A deployment that enabled no framework opted out of compliance; do not
        // tighten anything toward the resolver's baseline defaults.
        if ($config->enabledFrameworks === []) {
            return;
        }

        // The profile always carries a value for every numeric control (the
        // resolver's baseline default), even for controls NO enabled framework
        // mandates. Enforcing a numeric control on that baseline would tighten (or
        // fail-close) over a limit none of the operator's frameworks impose, and
        // misattribute it to them. Gate each numeric control on whether a framework
        // actually constrains it; the boolean controls self-guard (resolved to
        // their weakest value when unconstrained).
        $constraints = $resolver->constraints($config->enabledFrameworks);

        if ($constraints->sessionIdleTimeout) {
            $this->enforceSessionIdleTimeout($container, $repository, $profile, $config->strictMode);
        }

        // Boolean controls: the resolver already resolves these to true only when
        // an enabled framework requires them, so they self-guard (no constraint
        // flag needed).
        $this->enforceEncryptionAtRest($container, $repository, $profile, $config->strictMode);
        $this->enforceEncryptionInTransit($container, $repository, $profile, $config->strictMode);
        $this->enforceMfaRequirement($container, $repository, $profile, $config->strictMode);
    }

    /**
     * MFA: the profile expresses a SCOPE ('always' | 'privileged' |
     * 'sensitive-data' | 'none') but the only wired knob is the boolean
     * auth.two_factor.enabled, so enforcement collapses to "MFA required in any
     * scope implies the subsystem must be on". The scope itself cannot be
     * represented in config today, and enabling the subsystem registers the 2FA
     * services and route middleware aliases — it does not by itself force every
     * user to enrol, which is why the strict-mode message names the required scope.
     */
    private function enforceMfaRequirement(
        ContainerInterface $container,
        ConfigRepository $repository,
        ComplianceProfile $profile,
        bool $strictMode,
    ): void {
        if (!$profile->requiresMfa() || !$repository->has(SecurityConfig::class)) {
            return;
        }

        /** @var SecurityConfig $security */
        $security = $repository->get(SecurityConfig::class);
        $auth = $security->auth;

        // No auth section configured at all. Synthesizing one would invent guard
        // configuration the operator never wrote, so the honest options are to
        // refuse the boot or to report the gap loudly and leave it alone.
        if ($auth === null) {
            if ($strictMode) {
                throw ConfigException::complianceViolation(
                    sprintf('multi-factor authentication, scope "%s" (config/security.php auth.two_factor)', $profile->mfaRequirement),
                    'no auth section configured',
                    'an auth section with two_factor.enabled = true',
                    $this->frameworkLabels($profile),
                );
            }

            $this->warn($container, sprintf(
                'compliance: the active profile requires MFA (scope "%s", frameworks: %s) but '
                . 'config/security.php has no auth section, so it cannot be enforced. Add auth.two_factor.enabled = true.',
                $profile->mfaRequirement,
                implode(', ', $this->frameworkLabels($profile)),
            ));

            return;
        }

        if ($auth->twoFactor->enabled) {
            return; // already compliant
        }

        if ($strictMode) {
            throw ConfigException::complianceViolation(
                sprintf('multi-factor authentication, scope "%s" (config/security.php auth.two_factor.enabled)', $profile->mfaRequirement),
                'disabled (false)',
                'enabled (true)',
                $this->frameworkLabels($profile),
            );
        }

        $tightenedTwoFactor = $auth->twoFactor->withEnabled(true);
        $tightenedAuth = $auth->withTwoFactor($tightenedTwoFactor);
        $tightenedSecurity = $security->withAuth($tightenedAuth);

        $repository->set($tightenedSecurity);
        $container->instance(SecurityConfig::class, $tightenedSecurity);
        // ConfigWiring pre-registers these nested configs as standalone bindings
        // from the untightened aggregate; refresh them so an autowired consumer
        // cannot read a stale copy that still says MFA is off.
        $container->instance(AuthConfig::class, $tightenedAuth);
        $container->instance(TwoFactorConfig::class, $tightenedTwoFactor);

        $this->warn($container, sprintf(
            'compliance: two-factor authentication enabled to satisfy the active compliance '
            . 'profile (scope "%s", frameworks: %s).',
            $profile->mfaRequirement,
            implode(', ', $this->frameworkLabels($profile)),
        ));
    }

    /**
     * Encryption in transit maps onto two independent config surfaces, so both are
     * enforced: the HSTS assertion (headers) and the session cookie's Secure flag.
     */
    private function enforceEncryptionInTransit(
        ContainerInterface $container,
        ConfigRepository $repository,
        ComplianceProfile $profile,
        bool $strictMode,
    ): void {
        if (!$profile->encryptionInTransit || !$repository->has(SecurityConfig::class)) {
            return;
        }

        $this->enforceHstsAssertion($container, $repository, $profile, $strictMode);
        $this->enforceSecureSessionCookie($container, $repository, $profile, $strictMode);
    }

    /**
     * HSTS: the deployment must assert HTTPS somewhere. Compliant when the
     * structured config is enabled, when a literal Strict-Transport-Security
     * header is configured (both resolved by effectiveHstsHeader()), or when the
     * edge terminates TLS and emits HSTS itself — forcing `enabled` in that last
     * case would double-emit the header and override an intentional
     * edge-terminated setup. Safe to enforce in every environment: the middleware
     * emits HSTS only on secure requests (RFC 6797 §7.2), so over plain http it
     * changes nothing.
     */
    private function enforceHstsAssertion(
        ContainerInterface $container,
        ConfigRepository $repository,
        ComplianceProfile $profile,
        bool $strictMode,
    ): void {
        /** @var SecurityConfig $security */
        $security = $repository->get(SecurityConfig::class);
        $headers = $security->headers;

        if ($headers->effectiveHstsHeader() !== null || $headers->hsts->emittedAtEdge) {
            return; // HTTPS already asserted
        }

        if ($strictMode) {
            throw ConfigException::complianceViolation(
                'HSTS assertion (config/security.php headers.hsts.enabled)',
                'not asserted (disabled, and not emitted at the edge)',
                'enabled (or headers.hsts.emitted_at_edge for edge-terminated TLS)',
                $this->frameworkLabels($profile),
            );
        }

        $tightenedHeaders = $headers->withHsts($headers->hsts->withEnabled(true));
        $tightenedSecurity = $security->withHeaders($tightenedHeaders);

        $repository->set($tightenedSecurity);
        $container->instance(SecurityConfig::class, $tightenedSecurity);
        $container->instance(SecurityHeadersConfig::class, $tightenedHeaders);

        $this->warn($container, sprintf(
            'compliance: HSTS enabled to satisfy the active compliance profile '
            . '(frameworks: %s). Set headers.hsts.emitted_at_edge if TLS terminates at the edge.',
            implode(', ', $this->frameworkLabels($profile)),
        ));
    }

    /**
     * Session cookie Secure flag — enforced in PRODUCTION only.
     *
     * Outside production the framework deliberately relaxes this flag: a Secure
     * cookie is never returned over plain http://, which breaks the session (and
     * every CSRF-protected POST) on a local dev server, and there is no TLS to
     * protect there anyway. Forcing it would break the application without adding
     * any security — so the control applies where transport security is real.
     */
    private function enforceSecureSessionCookie(
        ContainerInterface $container,
        ConfigRepository $repository,
        ComplianceProfile $profile,
        bool $strictMode,
    ): void {
        if (!$repository->has(AppConfig::class)) {
            return;
        }

        /** @var AppConfig $app */
        $app = $repository->get(AppConfig::class);

        if ($app->mode !== EnvironmentMode::Production) {
            return;
        }

        /** @var SecurityConfig $security */
        $security = $repository->get(SecurityConfig::class);

        if ($security->session->cookieSecure) {
            return; // already compliant
        }

        if ($strictMode) {
            throw ConfigException::complianceViolation(
                'secure session cookie (config/security.php session.cookie_secure)',
                'disabled (false)',
                'enabled (true)',
                $this->frameworkLabels($profile),
            );
        }

        $this->applyTightenedSession($container, $repository, $security, $security->session->withCookieSecure(true));

        $this->warn($container, sprintf(
            'compliance: session cookie Secure flag enabled to satisfy the active '
            . 'compliance profile (frameworks: %s).',
            implode(', ', $this->frameworkLabels($profile)),
        ));
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

        $this->applyTightenedSession($container, $repository, $security, $security->session->withIdleTimeout($required));

        $this->warn($container, sprintf(
            'compliance: session idle timeout tightened from %s to %d s to satisfy the active '
            . 'compliance profile (frameworks: %s).',
            $current === 0 ? 'disabled' : $current . ' s',
            $required,
            implode(', ', $this->frameworkLabels($profile)),
        ));
    }

    /**
     * Encryption at rest: a boolean capability; compliant only when enabled.
     * SessionConfig.encryption is consulted at boot only when a master key is
     * present (SecurityWiring), so tightening the flag is the config-correct
     * action — the runtime additionally performing encryption depends on the
     * operator providing PULSAR_MASTER_KEY, which config cannot supply.
     */
    private function enforceEncryptionAtRest(
        ContainerInterface $container,
        ConfigRepository $repository,
        ComplianceProfile $profile,
        bool $strictMode,
    ): void {
        if (!$profile->encryptionAtRest || !$repository->has(SecurityConfig::class)) {
            return;
        }

        /** @var SecurityConfig $security */
        $security = $repository->get(SecurityConfig::class);

        if ($security->session->encryption) {
            return; // already compliant
        }

        if ($strictMode) {
            throw ConfigException::complianceViolation(
                'session encryption at rest (config/security.php session.encryption)',
                'disabled (false)',
                'enabled (true)',
                $this->frameworkLabels($profile),
            );
        }

        $this->applyTightenedSession($container, $repository, $security, $security->session->withEncryption(true));

        $this->warn($container, sprintf(
            'compliance: session encryption at rest enabled to satisfy the active '
            . 'compliance profile (frameworks: %s).',
            implode(', ', $this->frameworkLabels($profile)),
        ));
    }

    /**
     * Apply a tightened session config across the repository (the source of truth
     * SecurityWiring reads) AND the container bindings ConfigWiring pre-registered
     * from the untightened aggregate, so every consumer — whether it resolves
     * SecurityConfig from the repository or an autowired SessionConfig from the
     * container — sees the compliant value.
     */
    private function applyTightenedSession(
        ContainerInterface $container,
        ConfigRepository $repository,
        SecurityConfig $security,
        SessionConfig $tightenedSession,
    ): void {
        $tightenedSecurity = $security->withSession($tightenedSession);

        $repository->set($tightenedSecurity);
        $container->instance(SecurityConfig::class, $tightenedSecurity);
        $container->instance(SessionConfig::class, $tightenedSession);
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
