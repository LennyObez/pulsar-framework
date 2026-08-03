<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Pulsar\Cache\CacheAllowedClasses;
use Pulsar\Cache\CacheIntegrity;
use Pulsar\Cache\ConfigCache;
use Pulsar\Compliance\ComplianceConfig;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Config\AuthConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Config\TwoFactorConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ComplianceWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Core\Wiring\SecurityWiring;
use Pulsar\DataProtection\DataProtectionConfig;
use Pulsar\DataProtection\RetentionPolicy;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\SessionMetadata;
use Stringable;

use function bin2hex;
use function file_put_contents;
use function implode;
use function is_dir;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function time;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ComplianceWiring::class)]
final class ComplianceWiringTest extends TestCase
{
    private string $configPath = '';

    protected function tearDown(): void
    {
        if ($this->configPath !== '' && is_dir($this->configPath)) {
            $this->cleanDir($this->configPath);
        }
    }

    /** The strictest session idle timeout PCI-DSS mandates, computed from the resolver. */
    private function pciDssIdleTimeout(): int
    {
        return new ComplianceProfileResolver()
            ->resolve([ComplianceFramework::PciDss])
            ->sessionIdleTimeout;
    }

    #[Test]
    public function resolvesAndRegistersTheProfileInTheContainer(): void
    {
        [$container] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 60]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        self::assertTrue($container->has(ComplianceProfile::class));
        /** @var ComplianceProfile $profile */
        $profile = $container->get(ComplianceProfile::class);
        self::assertTrue($profile->hasFramework(ComplianceFramework::PciDss));
    }

    #[Test]
    public function tightensSessionIdleTimeoutWhenOperatorIsLooser(): void
    {
        $expected = $this->pciDssIdleTimeout();

        [$container, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertSame($expected, $security->session->idleTimeout, 'repository config must be tightened');

        // The container bindings are replaced too, so a consumer reading either the
        // aggregate or the nested config sees the compliant value.
        /** @var SecurityConfig $containerSecurity */
        $containerSecurity = $container->get(SecurityConfig::class);
        self::assertSame($expected, $containerSecurity->session->idleTimeout);
        /** @var SessionConfig $containerSession */
        $containerSession = $container->get(SessionConfig::class);
        self::assertSame($expected, $containerSession->idleTimeout);
    }

    #[Test]
    public function doesNotChangeSessionTimeoutWhenOperatorIsAlreadyStricter(): void
    {
        [, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 60]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertSame(60, $security->session->idleTimeout);
    }

    #[Test]
    public function treatsDisabledTimeoutAsWeakestAndTightensIt(): void
    {
        $expected = $this->pciDssIdleTimeout();

        [, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 0]", // 0 = no idle expiry = weakest
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertSame($expected, $security->session->idleTimeout);
    }

    #[Test]
    public function strictModeFailsClosedOnANonCompliantSetting(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('Compliance strict mode');

        $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999]",
            "'enabled_frameworks' => ['pci_dss'], 'verification' => ['strict_mode' => true]",
        );
    }

    #[Test]
    public function noEnabledFrameworksIsANoOp(): void
    {
        [$container, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999]",
            "'enabled_frameworks' => []",
        );

        // Profile still registered (baseline), but nothing is tightened.
        self::assertTrue($container->has(ComplianceProfile::class));
        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertSame(99999, $security->session->idleTimeout);
    }

    #[Test]
    public function doesNotEnforceSessionTimeoutWhenNoEnabledFrameworkConstrainsIt(): void
    {
        // GDPR mandates no session idle timeout (it implements no HasAccessControl).
        // The resolver still reports its 900 s baseline default in the profile, but
        // that baseline must NOT be enforced as though GDPR required it — otherwise
        // an operator is tightened (and falsely attributed) over a limit no enabled
        // framework imposes.
        [, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999]",
            "'enabled_frameworks' => ['gdpr']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertSame(99999, $security->session->idleTimeout, 'unconstrained baseline must not be enforced');
    }

    #[Test]
    public function strictModeDoesNotFailWhenNoFrameworkConstrainsTheControl(): void
    {
        // The fail-closed path must fire only for a control an enabled framework
        // actually mandates — never over the resolver's baseline default.
        [, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999]",
            "'enabled_frameworks' => ['gdpr'], 'verification' => ['strict_mode' => true]",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertSame(99999, $security->session->idleTimeout);
    }

    #[Test]
    public function enablesSessionEncryptionWhenRequiredAndOperatorDisabledIt(): void
    {
        // PCI-DSS requires encryption at rest; an operator who disabled session
        // encryption is looser than the profile and must be tightened to enabled.
        [$container, $configManager] = $this->bootAndWire(
            "'session' => ['encryption' => false]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertTrue($security->session->encryption, 'encryption at rest must be enabled');

        /** @var SessionConfig $containerSession */
        $containerSession = $container->get(SessionConfig::class);
        self::assertTrue($containerSession->encryption);
    }

    #[Test]
    public function doesNotChangeEncryptionWhenAlreadyEnabled(): void
    {
        // Default session.encryption is already true; enforcement is a no-op and
        // must never flip a compliant value.
        [, $configManager] = $this->bootAndWire(
            "'session' => ['encryption' => true]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertTrue($security->session->encryption);
    }

    #[Test]
    public function doesNotEnableEncryptionWhenNoFrameworkRequiresIt(): void
    {
        // SOC 2 does not require encryption at rest, so an operator who disabled it
        // stays disabled — compliance never invents a requirement no framework sets.
        [, $configManager] = $this->bootAndWire(
            "'session' => ['encryption' => false]",
            "'enabled_frameworks' => ['soc2']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertFalse($security->session->encryption);
    }

    #[Test]
    public function strictModeFailsClosedWhenEncryptionAtRestRequiredButDisabled(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('encryption at rest');

        $this->bootAndWire(
            "'session' => ['encryption' => false]",
            "'enabled_frameworks' => ['pci_dss'], 'verification' => ['strict_mode' => true]",
        );
    }

    #[Test]
    public function enablesHstsWhenHttpsIsAssertedNowhere(): void
    {
        // PCI-DSS requires encryption in transit; a deployment that asserts HTTPS
        // nowhere (HSTS disabled, nothing at the edge) must have HSTS turned on.
        [$container, $configManager] = $this->bootAndWire(
            "'headers' => ['hsts' => ['enabled' => false]]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertTrue($security->headers->hsts->enabled);

        /** @var SecurityHeadersConfig $containerHeaders */
        $containerHeaders = $container->get(SecurityHeadersConfig::class);
        self::assertTrue($containerHeaders->hsts->enabled, 'the standalone headers binding must be refreshed too');
    }

    #[Test]
    public function doesNotTouchHstsWhenTlsTerminatesAtTheEdge(): void
    {
        // Edge-terminated TLS: the edge emits HSTS, so the origin deliberately
        // keeps it disabled. Forcing it on would double-emit the header and
        // override an intentional deployment topology.
        [, $configManager] = $this->bootAndWire(
            "'headers' => ['hsts' => ['enabled' => false, 'emitted_at_edge' => true]]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertFalse($security->headers->hsts->enabled, 'edge-terminated HSTS must not be overridden');
    }

    #[Test]
    public function doesNotEnableHstsWhenNoFrameworkRequiresEncryptionInTransit(): void
    {
        // SOC 2 mandates no encryption in transit: an operator's disabled HSTS
        // must stay disabled — compliance never invents a requirement.
        [, $configManager] = $this->bootAndWire(
            "'headers' => ['hsts' => ['enabled' => false]]",
            "'enabled_frameworks' => ['soc2']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertFalse($security->headers->hsts->enabled);
    }

    #[Test]
    public function doesNotForceSecureCookieOutsideProduction(): void
    {
        // Outside production a Secure cookie is never returned over plain http://,
        // which would break every session and CSRF-protected POST on a dev server —
        // and there is no TLS to protect anyway. The control must stay inert there.
        [, $configManager] = $this->bootAndWire(
            "'session' => ['cookie_secure' => false]",
            "'enabled_frameworks' => ['pci_dss']",
            null,
            "'name' => 'T', 'env' => 'local'",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertFalse($security->session->cookieSecure, 'dev sessions must not be broken by compliance');
    }

    #[Test]
    public function forcesSecureCookieInProduction(): void
    {
        [, $configManager] = $this->bootAndWire(
            "'session' => ['cookie_secure' => false]",
            "'enabled_frameworks' => ['pci_dss']",
            null,
            "'name' => 'T', 'env' => 'production'",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertTrue($security->session->cookieSecure);
    }

    #[Test]
    public function strictModeFailsClosedWhenHttpsIsAssertedNowhere(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('HSTS assertion');

        $this->bootAndWire(
            "'headers' => ['hsts' => ['enabled' => false]]",
            "'enabled_frameworks' => ['pci_dss'], 'verification' => ['strict_mode' => true]",
        );
    }

    #[Test]
    public function enablesTwoFactorWhenTheProfileRequiresMfa(): void
    {
        // PCI-DSS requires MFA (scope "privileged"); an operator with the auth
        // section present but 2FA off is looser than the profile.
        [$container, $configManager] = $this->bootAndWire(
            "'auth' => ['two_factor' => ['enabled' => false]]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertNotNull($security->auth);
        self::assertTrue($security->auth->twoFactor->enabled);

        // The standalone bindings ConfigWiring pre-registers must be refreshed too,
        // or an autowired consumer would still read "MFA off".
        /** @var TwoFactorConfig $containerTwoFactor */
        $containerTwoFactor = $container->get(TwoFactorConfig::class);
        self::assertTrue($containerTwoFactor->enabled);
        /** @var AuthConfig $containerAuth */
        $containerAuth = $container->get(AuthConfig::class);
        self::assertTrue($containerAuth->twoFactor->enabled);
    }

    #[Test]
    public function doesNotChangeTwoFactorWhenAlreadyEnabled(): void
    {
        [, $configManager] = $this->bootAndWire(
            "'auth' => ['two_factor' => ['enabled' => true]]",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertNotNull($security->auth);
        self::assertTrue($security->auth->twoFactor->enabled);
    }

    #[Test]
    public function doesNotEnableTwoFactorWhenNoFrameworkRequiresMfa(): void
    {
        // GDPR resolves mfaRequirement to 'none', so the control self-guards: an
        // operator who left 2FA off stays off.
        [, $configManager] = $this->bootAndWire(
            "'auth' => ['two_factor' => ['enabled' => false]]",
            "'enabled_frameworks' => ['gdpr']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertNotNull($security->auth);
        self::assertFalse($security->auth->twoFactor->enabled);
    }

    #[Test]
    public function strictModeFailsClosedWhenMfaRequiredButDisabled(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('multi-factor authentication');

        $this->bootAndWire(
            "'auth' => ['two_factor' => ['enabled' => false]]",
            "'enabled_frameworks' => ['pci_dss'], 'verification' => ['strict_mode' => true]",
        );
    }

    #[Test]
    public function warnsRatherThanInventingConfigWhenNoAuthSectionExists(): void
    {
        // config/security.php may legitimately omit `auth` entirely. Synthesizing
        // one would invent guard configuration the operator never wrote, so the
        // gap is reported loudly instead of being silently papered over.
        $spy = new ComplianceWarningSpyLogger();

        [, $configManager] = $this->bootAndWire(
            "'session' => ['cookie_name' => 'T']", // no 'auth' key at all
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        self::assertNull($security->auth, 'no auth section must be invented');
        self::assertTrue(
            $spy->has('no auth section'),
            'the unenforceable requirement must be reported; got: ' . $spy->dump(),
        );
    }

    #[Test]
    public function strictModeFailsClosedWhenMfaRequiredAndNoAuthSectionExists(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('no auth section configured');

        $this->bootAndWire(
            "'session' => ['cookie_name' => 'T']",
            "'enabled_frameworks' => ['pci_dss'], 'verification' => ['strict_mode' => true]",
        );
    }

    #[Test]
    public function extendsAuditRetentionThatIsTooShort(): void
    {
        $required = new ComplianceProfileResolver()->resolve([ComplianceFramework::PciDss])->auditRetentionDays;

        [$container, $configManager] = $this->bootAndWire(
            "'session' => ['cookie_name' => 'T']",
            "'enabled_frameworks' => ['pci_dss']",
            null,
            '',
            "'retention' => [['category' => 'audit_logs', 'retention_days' => 30]]",
        );

        /** @var DataProtectionConfig $dp */
        $dp = $configManager->repository()->get(DataProtectionConfig::class);
        self::assertSame($required, $this->auditRetentionDays($dp));

        /** @var DataProtectionConfig $containerDp */
        $containerDp = $container->get(DataProtectionConfig::class);
        self::assertSame($required, $this->auditRetentionDays($containerDp));
    }

    #[Test]
    public function neverOverwritesIndefiniteAuditRetention(): void
    {
        // THE trap of this control: retention_days = 0 means INDEFINITE retention
        // (DefaultRetentionPolicy::isExpired never expires it), which is the
        // STRICTEST possible setting. A naive max(current, required) would replace
        // it with a finite value and start deleting audit records that were being
        // kept forever — a catastrophic LOOSENING disguised as enforcement.
        [, $configManager] = $this->bootAndWire(
            "'session' => ['cookie_name' => 'T']",
            "'enabled_frameworks' => ['pci_dss']",
            null,
            '',
            "'retention' => [['category' => 'audit_logs', 'retention_days' => 0]]",
        );

        /** @var DataProtectionConfig $dp */
        $dp = $configManager->repository()->get(DataProtectionConfig::class);
        self::assertSame(0, $this->auditRetentionDays($dp), 'indefinite retention must survive untouched');
    }

    #[Test]
    public function doesNotShortenAuditRetentionThatAlreadyExceedsTheRequirement(): void
    {
        [, $configManager] = $this->bootAndWire(
            "'session' => ['cookie_name' => 'T']",
            "'enabled_frameworks' => ['pci_dss']",
            null,
            '',
            "'retention' => [['category' => 'audit_logs', 'retention_days' => 2555]]",
        );

        /** @var DataProtectionConfig $dp */
        $dp = $configManager->repository()->get(DataProtectionConfig::class);
        self::assertSame(2555, $this->auditRetentionDays($dp), 'compliance must never shorten retention');
    }

    #[Test]
    public function doesNotSynthesizeAPolicyWhenTheAuditCategoryIsAbsent(): void
    {
        // A MISSING audit_logs policy is not a gap to fill: DataPurgeOrchestrator
        // skips any category it has no policy for, so audit logs are never purged —
        // indefinite retention, which already exceeds any finite requirement.
        // Adding a finite policy here would START deleting records that were kept
        // forever: the same loosening as overwriting a configured 0.
        [, $configManager] = $this->bootAndWire(
            "'session' => ['cookie_name' => 'T']",
            "'enabled_frameworks' => ['pci_dss']",
            null,
            '',
            "'retention' => [['category' => 'user_sessions', 'retention_days' => 90]]",
        );

        /** @var DataProtectionConfig $dp */
        $dp = $configManager->repository()->get(DataProtectionConfig::class);
        self::assertNull($this->policyFor($dp, 'audit_logs'), 'no purge policy must be invented');
        // The unrelated category must be untouched.
        $sessionPolicy = $this->policyFor($dp, 'user_sessions');
        self::assertNotNull($sessionPolicy);
        self::assertSame(90, $sessionPolicy->retentionDays);
    }

    #[Test]
    public function strictModeAcceptsAnAbsentAuditPolicyAsIndefiniteRetention(): void
    {
        // Fail-closed must not fire on a configuration that is STRICTER than the
        // requirement (never purged), which is what an absent policy means. The
        // auth section is provided so the unrelated MFA control is satisfied and
        // only the audit-retention behaviour is under test.
        [, $configManager] = $this->bootAndWire(
            "'auth' => ['two_factor' => ['enabled' => true]]",
            "'enabled_frameworks' => ['pci_dss'], 'verification' => ['strict_mode' => true]",
            null,
            '',
            "'retention' => [['category' => 'user_sessions', 'retention_days' => 90]]",
        );

        /** @var DataProtectionConfig $dp */
        $dp = $configManager->repository()->get(DataProtectionConfig::class);
        self::assertNull($this->policyFor($dp, 'audit_logs'));
    }

    #[Test]
    public function tightensEveryDuplicateAuditPolicyBecauseTheConsumerKeepsTheLast(): void
    {
        // SecurityWiring collapses the retention list into a category-keyed map, so
        // a duplicated category is resolved LAST-wins. Inspecting only the first
        // entry would let a deployment pass the check while purging audit logs on
        // the second entry's much shorter period.
        $required = new ComplianceProfileResolver()->resolve([ComplianceFramework::PciDss])->auditRetentionDays;

        [, $configManager] = $this->bootAndWire(
            "'session' => ['cookie_name' => 'T']",
            "'enabled_frameworks' => ['pci_dss']",
            null,
            '',
            "'retention' => [['category' => 'audit_logs', 'retention_days' => 0], ['category' => 'audit_logs', 'retention_days' => 30]]",
        );

        /** @var DataProtectionConfig $dp */
        $dp = $configManager->repository()->get(DataProtectionConfig::class);

        $auditPolicies = [];

        foreach ($dp->retention as $policy) {
            if ($policy->category === 'audit_logs') {
                $auditPolicies[] = $policy->retentionDays;
            }
        }

        // The indefinite entry stays indefinite; the short one is extended, so the
        // effective (last) policy is compliant whichever entry the consumer keeps.
        self::assertSame([0, $required], $auditPolicies);
    }

    #[Test]
    public function strictModeFailsClosedOnTooShortAuditRetention(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('audit log retention');

        $this->bootAndWire(
            "'session' => ['cookie_name' => 'T']",
            "'enabled_frameworks' => ['pci_dss'], 'verification' => ['strict_mode' => true]",
            null,
            '',
            "'retention' => [['category' => 'audit_logs', 'retention_days' => 30]]",
        );
    }

    private function auditRetentionDays(DataProtectionConfig $config): ?int
    {
        return $this->policyFor($config, 'audit_logs')?->retentionDays;
    }

    private function policyFor(DataProtectionConfig $config, string $category): ?RetentionPolicy
    {
        foreach ($config->retention as $policy) {
            if ($policy->category === $category) {
                return $policy;
            }
        }

        return null;
    }

    #[Test]
    public function theTightenedTimeoutIsActuallyEnforcedBySessionManager(): void
    {
        // End-to-end runtime proof (not just config state): an operator-loose idle
        // timeout of 99999 s, tightened by PCI-DSS to 900 s, must make SessionManager
        // EXPIRE an authenticated session idle for 1000 s — a session that would have
        // SURVIVED under the operator's un-tightened 99999 s. This proves the
        // tightening reaches the real consumer and changes behaviour, not just a field.
        [, $configManager] = $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999, 'cookie_name' => 'TEST_SESSION']",
            "'enabled_frameworks' => ['pci_dss']",
        );

        /** @var SecurityConfig $security */
        $security = $configManager->repository()->get(SecurityConfig::class);
        $tightenedSession = $security->session;
        self::assertSame($this->pciDssIdleTimeout(), $tightenedSession->idleTimeout);

        $handler = new ArrayHandler();
        $sessionId = bin2hex(random_bytes(32));
        $metadata = new SessionMetadata(
            createdAt: time() - 3600,
            lastActivity: time() - 1000, // idle 1000 s: past the tightened 900, well within 99999
            ipAddress: '127.0.0.1',
            userAgent: 'TestAgent',
        );
        self::assertTrue($handler->open('', 'TEST_SESSION'));
        $handler->write($sessionId, json_encode([
            'data' => ['_pulsar_identity' => ['id' => 'user-123']],
            '_pulsar_meta' => $metadata->toArray(),
        ], JSON_THROW_ON_ERROR));

        $manager = new SessionManager($handler, $tightenedSession);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => 'TestAgent'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            cookieParams: ['TEST_SESSION' => $sessionId],
        );

        // Authenticated idle-expired session throws (PCI-DSS 8.2.8 re-auth) — proving
        // the compliance-tightened timeout, not the operator's value, is in force.
        $this->expectException(SecurityException::class);
        $manager->startWithRequest($request);
    }

    #[Test]
    public function enforcementAppliesAfterAConfigCacheRoundTrip(): void
    {
        // Production `optimize` serializes the whole ConfigRepository and restores it
        // via ConfigManager::loadFromCache on a cache hit; the wiring loop then runs
        // unconditionally (Kernel config phase). Enforcement must therefore survive
        // the real cache round-trip: the loader-built ComplianceConfig must persist
        // through the cache's restricted-allowlist deserialization, and ComplianceWiring
        // must still tighten the cached (untightened) SecurityConfig.
        $expected = $this->pciDssIdleTimeout();

        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_compliance_cache_' . bin2hex(random_bytes(4));
        mkdir($this->configPath, 0o755, true);
        $this->write('app.php', '');
        $this->write('observability.php', '');
        $this->write('security.php', "'session' => ['idle_timeout' => 99999]");
        $this->write('compliance.php', "'enabled_frameworks' => ['pci_dss']");

        // Build the repository exactly as a fresh boot would (loader + load).
        $builder = new ConfigManager($this->configPath);
        $wiring = new ComplianceWiring();
        ConfigLoaderRegistrar::register($builder, [$wiring]);
        $builder->load();

        // Round-trip through the REAL config cache: write the repository, then load
        // it back with the same content-derived allowlist production uses. This
        // proves ComplianceConfig (and its ComplianceFramework enum list) is covered
        // by the allowlist and survives deserialization.
        $cache = new ConfigCache(new CacheIntegrity(new HmacService(), random_bytes(32)));
        $cache->write($this->configPath, $builder->repository(), false);
        $allowed = CacheAllowedClasses::extractFromSerialized(serialize($builder->repository()));
        $restored = $cache->load($this->configPath, $allowed);
        self::assertInstanceOf(ConfigRepository::class, $restored);

        $cached = new ConfigManager($this->configPath);
        self::assertTrue($cached->loadFromCache($restored), 'cache must restore a valid repository');
        // The loader-built ComplianceConfig must have survived caching.
        self::assertTrue($cached->repository()->has(ComplianceConfig::class));

        $container = new Container();
        $wiring->wire($container, $cached, new MiddlewarePipeline($container), new MiddlewareRegistry(), new Router());

        /** @var SecurityConfig $security */
        $security = $cached->repository()->get(SecurityConfig::class);
        self::assertSame($expected, $security->session->idleTimeout, 'enforcement must apply on the cached boot path');
    }

    #[Test]
    public function tighteningEmitsABootWarning(): void
    {
        $spy = new ComplianceWarningSpyLogger();

        $this->bootAndWire(
            "'session' => ['idle_timeout' => 99999]",
            "'enabled_frameworks' => ['pci_dss']",
            $spy,
        );

        self::assertTrue(
            $spy->has('session idle timeout tightened'),
            'a tighten warning must be logged; got: ' . $spy->dump(),
        );
        self::assertTrue($spy->has('pci_dss'), 'the warning must name the active framework');
    }

    /**
     * Boot config through the ComplianceWiring loader exactly as the kernel does
     * (register loader, load), then wire it against a fresh container.
     *
     * @return array{0: Container, 1: ConfigManager}
     */
    private function bootAndWire(
        string $securityBody,
        string $complianceBody,
        ?LoggerInterface $logger = null,
        string $appBody = '',
        ?string $dataProtectionBody = null,
    ): array {
        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_compliance_wiring_' . bin2hex(random_bytes(4));
        mkdir($this->configPath, 0o755, true);

        $this->write('app.php', $appBody);
        $this->write('observability.php', '');
        $this->write('security.php', $securityBody);
        $this->write('compliance.php', $complianceBody);

        if ($dataProtectionBody !== null) {
            $this->write('data_protection.php', $dataProtectionBody);
        }

        $configManager = new ConfigManager($this->configPath);
        $wiring = new ComplianceWiring();
        // SecurityWiring owns the data_protection loader; register it alongside so
        // DataProtectionConfig reaches the repository exactly as it does at boot.
        ConfigLoaderRegistrar::register($configManager, [$wiring, new SecurityWiring()]);
        $configManager->load();

        $container = new Container();
        if ($logger !== null) {
            $container->instance(LoggerInterface::class, $logger);
        }

        $wiring->wire($container, $configManager, new MiddlewarePipeline($container), new MiddlewareRegistry(), new Router());

        return [$container, $configManager];
    }

    private function write(string $file, string $body): void
    {
        file_put_contents($this->configPath . DIRECTORY_SEPARATOR . $file, '<?php return [' . $body . '];');
    }

    private function cleanDir(string $dir): void
    {
        $items = scandir($dir);

        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                is_dir($path) ? $this->cleanDir($path) : unlink($path);
            }
        }

        rmdir($dir);
    }
}

/**
 * Minimal PSR-3 logger that records warning messages for assertion.
 */
final class ComplianceWarningSpyLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $warnings = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::WARNING) {
            $this->warnings[] = (string) $message;
        }
    }

    public function has(string $needle): bool
    {
        foreach ($this->warnings as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function dump(): string
    {
        return $this->warnings === [] ? '(no warnings)' : implode(' | ', $this->warnings);
    }
}
