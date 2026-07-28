<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use PDO;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Cache\Application\CacheManagerInterface;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DeployConfig;
use Pulsar\Config\DomainConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\RateLimitConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Core\Wiring\Internal\ReportsConfigKeys;
use Pulsar\DataProtection\AuditLogPurge;
use Pulsar\DataProtection\ConsentManagerInterface;
use Pulsar\DataProtection\DataProtectionConfig;
use Pulsar\DataProtection\DataPurgeInterface;
use Pulsar\DataProtection\DataPurgeOrchestrator;
use Pulsar\DataProtection\DefaultRetentionPolicy;
use Pulsar\DataProtection\InMemoryConsentManager;
use Pulsar\DataProtection\RetentionPolicyInterface;
use Pulsar\DataProtection\SessionPurge;
use Pulsar\ErrorHandling\ExceptionRendererInterface;
use Pulsar\Filesystem\WritablePathGuard;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\RateLimitMiddleware;
use Pulsar\Http\RateLimit\CacheRateLimiter;
use Pulsar\Http\RateLimit\RateLimiter;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\RateLimit\RateLimitKeyStrategy;
use Pulsar\Http\TrustedProxy;
use Pulsar\Routing\DomainResolverInterface;
use Pulsar\Routing\Internal\ConfigDomainResolver;
use Pulsar\Routing\Router;
use Pulsar\Routing\SubdomainRoutingMiddleware;
use Pulsar\Security\Assertion\SecurityAssertionRunner;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Crypto\AesGcmCipherSuite;
use Pulsar\Security\Crypto\CipherSuiteInterface;
use Pulsar\Security\Crypto\CompositeKeyProvider;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\InMemoryTokenStore;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\SodiumCipherSuite;
use Pulsar\Security\Crypto\TokenizationService;
use Pulsar\Security\Crypto\TokenizationServiceInterface;
use Pulsar\Security\Crypto\TokenStoreInterface;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManager;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\InMemoryIncidentReporter;
use Pulsar\Security\Middleware\SecurityHeadersMiddleware;
use Pulsar\Security\Posture\SecurityPostureConfig;
use Pulsar\Security\Session\Flash\FlashBag;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\Handler\CookieHandler;
use Pulsar\Security\Session\Handler\DatabaseHandler;
use Pulsar\Security\Session\Handler\FileHandler;
use Pulsar\Security\Session\Handler\RedisHandler;
use Pulsar\Security\Session\Handler\SessionHandlerInterface;
use Pulsar\Security\Session\SessionEncryption;
use Pulsar\Security\Session\SessionInterface;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\SessionMiddleware;
use Pulsar\Security\Session\Validator\FingerprintValidator;
use Pulsar\Security\Session\Validator\RemoteAddressValidator;
use Pulsar\Security\Session\Validator\SessionValidatorInterface;
use Pulsar\Security\Session\Validator\UserAgentValidator;
use Pulsar\Security\Vault\SecretVault;
use Random\Randomizer;
use Redis;
use SodiumException;

use function dirname;
use function is_array;
use function is_file;
use function sodium_hex2bin;
use function sprintf;

use const DIRECTORY_SEPARATOR;

#[Internal]
final readonly class SecurityWiring implements ServiceWiringInterface, DescribesWiring
{
    use ReportsConfigKeys;

    /**
     * The security controls this wiring binds unconditionally on every boot.
     * Declaring them puts them under the wiring-contract gate, which asserts
     * each actually resolves from the booted container — so a control that is
     * built and documented but silently loses its binding fails the build
     * (the audit's dominant "built-but-never-wired" failure mode). Master-key-
     * gated bindings (crypto, tokenization, audit chain) are deliberately not
     * listed here: they are conditional on PULSAR_MASTER_KEY, not always-on.
     */
    public function describeWiring(): WiringContract
    {
        return new WiringContract(
            component: 'security',
            configClass: SecurityConfig::class,
            configFile: 'security.php',
            provides: [
                HmacInterface::class,
                SessionInterface::class,
                SessionManager::class,
                SessionHandlerInterface::class,
                SessionMiddleware::class,
                FlashBag::class,
                CsrfTokenManager::class,
                CsrfTokenManagerInterface::class,
                CsrfMiddleware::class,
                SecurityHeadersMiddleware::class,
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
        $environment = $configManager->environment();

        /** @var SecurityConfig $securityConfig */
        $securityConfig = $configManager->repository()->get(SecurityConfig::class);

        // HmacService adapter: always available (no key required, delegates to static Hmac methods)
        $hmacService = new HmacService();
        $container->instance(HmacInterface::class, $hmacService);

        // Crypto + Audit (only if master key is available)
        $masterKeyHex = $environment->get('PULSAR_MASTER_KEY');
        $masterKey = null;
        $sessionEncryption = null;

        if ($masterKeyHex !== null && $masterKeyHex !== '') {
            try {
                $previousKeyHex = $environment->get('PULSAR_MASTER_KEY_PREVIOUS');
                $masterKey = MasterKey::fromHex(
                    $masterKeyHex,
                    ($previousKeyHex !== null && $previousKeyHex !== '') ? $previousKeyHex : null,
                );
                $container->instance(MasterKey::class, $masterKey);

                // Build key provider: use CompositeKeyProvider if overrides are present
                $keyProvider = $this->buildKeyProvider($masterKey, $environment);
                $container->instance(KeyProviderInterface::class, $keyProvider);
                if ($keyProvider instanceof CompositeKeyProvider) {
                    $container->instance(CompositeKeyProvider::class, $keyProvider);
                }

                // Cipher suite selection from config
                $cipherSuite = $this->buildCipherSuite($securityConfig->cipherSuite);
                $container->instance(CipherSuiteInterface::class, $cipherSuite);

                $encryptor = Encryptor::fromMasterKey($masterKey, $cipherSuite);
                $container->instance(Encryptor::class, $encryptor);
                $container->instance(EncryptorInterface::class, $encryptor);

                // Tokenization service (PCI-DSS Req 3.4)
                $tokenStore = $container->has(TokenStoreInterface::class)
                    ? $container->get(TokenStoreInterface::class)
                    : new InMemoryTokenStore();
                $container->instance(TokenStoreInterface::class, $tokenStore);
                $tokenizationService = new TokenizationService($masterKey, $tokenStore, $cipherSuite);
                $container->instance(TokenizationService::class, $tokenizationService);
                $container->instance(TokenizationServiceInterface::class, $tokenizationService);

                // Session encryption via Keyring (Finding B)
                if ($securityConfig->session->encryption) {
                    $sessionEncryption = SessionEncryption::fromMasterKey($masterKey);
                    $container->instance(SessionEncryption::class, $sessionEncryption);
                }

                // Framework cache (skip if pre-boot already registered)
                if (!$container->has(FrameworkCache::class)) {
                    $encrypt = $environment->get('CACHE_ENCRYPT') === 'true'
                        || $environment->get('CACHE_ENCRYPT') === '1';
                    $configPath = $configManager->configPath();
                    if ($configPath !== null) {
                        $frameworkCache = new FrameworkCache(dirname($configPath), $masterKey, $hmacService, $encrypt, $encrypt ? $encryptor : null, $environment);
                        $container->instance(FrameworkCache::class, $frameworkCache);
                        $container->instance(FrameworkCacheInterface::class, $frameworkCache);
                    }
                }

                /** @var Randomizer $randomizer */
                $randomizer = $container->get(Randomizer::class);

                // Audit logger with HMAC chain
                /** @var ObservabilityConfig $obsConfig */
                $obsConfig = $configManager->repository()->get(ObservabilityConfig::class);

                if ($obsConfig->audit->enabled) {
                    $auditKey = $masterKey->deriveSubKey(2, 'audit___');

                    // F24.3: route AuditFileSink corruption diagnostics
                    // through the application logger when one is wired,
                    // so operators see them in the same structured
                    // pipeline as other security warnings. Falls back to
                    // NullLogger when no logger is registered yet —
                    // SecurityException::auditChainCorrupted() still
                    // surfaces the failure synchronously regardless.
                    $auditSinkLogger = $container->has(LoggerInterface::class)
                        ? $container->get(LoggerInterface::class)
                        : null;

                    /** @var LoggerInterface|null $auditSinkLogger */
                    $auditSink = new AuditFileSink(
                        WritablePathGuard::resolveState($obsConfig->audit->logPath, 'observability.audit.log_path'),
                        false,
                        $auditSinkLogger,
                    );
                    $container->instance(AuditSinkInterface::class, $auditSink);
                    $container->instance(AuditFileSink::class, $auditSink);

                    $contextHolder = $container->has(RequestContextHolder::class)
                        ? $container->get(RequestContextHolder::class)
                        : null;

                    /** @var RequestContextHolder|null $contextHolder */
                    $auditLogger = new AuditLogger($auditSink, $auditKey, $randomizer, $contextHolder);
                    $container->instance(AuditLogger::class, $auditLogger);
                    $container->instance(AuditLoggerInterface::class, $auditLogger);

                    // Key ring + chain verifier for audit verification across key rotations
                    $auditKeyRing = EnvKeyRing::fromMasterKey($masterKey, 2, 'audit___');
                    $container->instance(KeyRingInterface::class, $auditKeyRing);
                    $container->instance(EnvKeyRing::class, $auditKeyRing);

                    $chainVerifier = new AuditChainVerifier($auditKeyRing);
                    $container->instance(AuditChainVerifier::class, $chainVerifier);
                }
                // Secret vault: encrypted config secrets (API keys, credentials, DSN strings)
                $configPath = $configManager->configPath();
                if ($configPath !== null) {
                    $vaultPath = dirname($configPath) . DIRECTORY_SEPARATOR . 'secrets.encrypted.php';
                    $vault = SecretVault::create($masterKey, $vaultPath);
                    $container->instance(SecretVault::class, $vault);
                }
            } catch (SecurityException | SodiumException) {
                // Master key is invalid or sodium operation failed: skip crypto/audit registration.
                // Session, CSRF, and headers still work without it.
                $masterKey = null;
                $sessionEncryption = null;
            }
        }

        // Security assertions: verify security posture in production mode
        $appEnv = $environment->get('APP_ENV') ?? 'local';
        if ($appEnv === 'production') {
            $repository = $configManager->repository();
            $debugMode = $repository->has(AppConfig::class)
                && $repository->get(AppConfig::class)->debug;

            $assertionRunner = new SecurityAssertionRunner(
                debugMode: $debugMode,
                hstsEnabled: $securityConfig->headers->hsts->enabled,
                // Same Environment-resolved value the crypto stack uses (OS env +
                // .env), so a key provided only in .env is not falsely reported
                // missing — see $masterKeyHex resolved at the top of wire().
                masterKeyHex: $masterKeyHex,
                hstsConfig: $securityConfig->headers->hsts,
                sessionConfig: $securityConfig->session,
            );
            $container->instance(SecurityAssertionRunner::class, $assertionRunner);

            // Advisory boot log of each violation. Security posture is a config
            // invariant, so under PHP-FPM (boot==request) logging it every boot
            // floods the log with an unchanging state -- gated by logAtBoot (off
            // in production by default; /health + `security:check` are the prod
            // channels, and callers use assertAll() for strict enforcement).
            // The runner stays bound above regardless, for CLI/on-demand use.
            if (SecurityPostureConfig::fromEnvironment($environment)->logAtBoot) {
                $violations = $assertionRunner->check();

                if ($violations !== [] && $container->has(LoggerInterface::class)) {
                    /** @var LoggerInterface $logger */
                    $logger = $container->get(LoggerInterface::class);

                    foreach ($violations as $violation) {
                        $logger->warning(
                            sprintf('%s: %s', $violation->assertion, $violation->message),
                            [
                                'assertion' => $violation->assertion,
                                'severity' => $violation->severity->value,
                                'category' => 'security',
                            ],
                        );
                    }
                }
            }
        }

        // Trusted-proxy-aware client-IP resolution, shared by session capture,
        // the session validators, and (via the container) hijack/account-takeover
        // detection — so all of them resolve the same client IP behind a proxy
        // and never disagree (which would cause false-positive session/hijack
        // alerts). Constructed only when proxies are configured; otherwise null,
        // which preserves the raw-REMOTE_ADDR behaviour.
        $repository = $configManager->repository();
        /** @var list<string> $trustedProxies */
        $trustedProxies = $repository->has(DeployConfig::class)
            ? $repository->get(DeployConfig::class)->trustedProxies
            : [];
        $trustedProxy = $trustedProxies !== [] ? new TrustedProxy($trustedProxies) : null;
        if ($trustedProxy !== null) {
            $container->instance(TrustedProxy::class, $trustedProxy);
        }

        // Session: build handler, validators, and manager
        $sessionHandler = $this->buildSessionHandler($securityConfig, $container, $sessionEncryption);
        $container->instance(SessionHandlerInterface::class, $sessionHandler);

        $validators = $this->buildValidators($securityConfig, $hmacService, $masterKey, $trustedProxy);

        $sessionManager = new SessionManager(
            $sessionHandler,
            $securityConfig->session,
            $validators,
            $sessionEncryption,
            $trustedProxy,
        );
        $container->instance(SessionManager::class, $sessionManager);
        $container->instance(SessionInterface::class, $sessionManager);

        // Flash messages
        $flashBag = new FlashBag($sessionManager);
        $container->instance(FlashBag::class, $flashBag);

        // Lazy resolver: the error-page renderer is wired by ExceptionHandlerWiring,
        // which runs after this wiring, so the session and CSRF middleware resolve it
        // at request time to theme their 4xx pages (mirrors ExceptionHandlerWiring's
        // own templateEngineResolver pattern).
        $errorRendererResolver = static function () use ($container): ?ExceptionRendererInterface {
            if (!$container->has(ExceptionRendererInterface::class)) {
                return null;
            }

            /** @var ExceptionRendererInterface $renderer */
            $renderer = $container->get(ExceptionRendererInterface::class);

            return $renderer;
        };

        // Session middleware
        /** @var LoggerInterface|null $sessionLogger */
        $sessionLogger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;
        $sessionMiddleware = new SessionMiddleware($sessionManager, $flashBag, $sessionLogger, $errorRendererResolver);
        $container->instance(SessionMiddleware::class, $sessionMiddleware);

        // CSRF (uses SessionManager which implements SessionInterface)
        if (!$container->has(Randomizer::class)) {
            $randomizer = new Randomizer();
            $container->instance(Randomizer::class, $randomizer);
        }
        /** @var Randomizer $csrfRandomizer */
        $csrfRandomizer = $container->get(Randomizer::class);
        $csrfTokenManager = new CsrfTokenManager($sessionManager, $securityConfig->csrf, $csrfRandomizer);
        $container->instance(CsrfTokenManager::class, $csrfTokenManager);
        $container->instance(CsrfTokenManagerInterface::class, $csrfTokenManager);

        $csrfMiddleware = new CsrfMiddleware($csrfTokenManager, $securityConfig->csrf, $errorRendererResolver);
        $container->instance(CsrfMiddleware::class, $csrfMiddleware);

        // Security Headers: gate X-Forwarded-Proto on trusted proxy IPs (CFR-71).
        // Reuses the $trustedProxies resolved above for the session/IP stack.
        $headersMiddleware = new SecurityHeadersMiddleware($securityConfig->headers, $trustedProxies);
        $container->instance(SecurityHeadersMiddleware::class, $headersMiddleware);

        // Warn at boot when a literal header in `headers` shadows an active
        // structured sub-config with a different value (e.g. a literal
        // Strict-Transport-Security overriding the typed `hsts` block, or a
        // literal Permissions-Policy overriding `permissions_policy`). The literal
        // is authoritative ("what you write is what's emitted"); surfacing the
        // override keeps it from being silent in either direction.
        //
        // Like the posture advisory above, this is a CONFIG invariant — it cannot
        // change between requests — so under a per-request SAPI (PHP-FPM:
        // boot==request) an unconditional warning would flood the log with an
        // unchanging state. Gate it behind the same logAtBoot flag (on outside
        // production, off in production) so the override is surfaced during
        // development without repeating on every production request.
        $shadowedHeaders = $securityConfig->headers->shadowedStructuredHeaders();
        if ($shadowedHeaders !== [] && SecurityPostureConfig::fromEnvironment($environment)->logAtBoot) {
            /** @var LoggerInterface|null $headersLogger */
            $headersLogger = $container->has(LoggerInterface::class)
                ? $container->get(LoggerInterface::class)
                : null;

            foreach ($shadowedHeaders as $conflict) {
                $headersLogger?->warning($conflict, ['component' => 'security.headers']);
            }
        }

        // Pipe globally (F9.1, F12.10): every response — including routes
        // that do not opt into the `web` / `api` middleware groups — must
        // carry the X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
        // CSP, and Cross-Origin baseline. Relying on per-route opt-in left
        // diagnostics endpoints, JSON APIs declared outside groups, error
        // pages, and ad-hoc routes exposed with no headers at all. The
        // middleware is idempotent for header VALUES (it sets `withHeader`,
        // which replaces existing values) so groups that include it again
        // produce the same result — but it must be piped exactly once into
        // the global pipeline: a second global pipe would run the CSP
        // builder, frame setter, and HSTS injector twice on every response.
        //
        // CSRF stays group-only because POST-only API endpoints legitimately
        // need to opt out, and an auto-pipe would break stateless
        // bearer-token flows.
        $middleware->pipe($headersMiddleware);

        // Incident Reporter: default to in-memory, override with FileIncidentReporter via config
        if (!$container->has(IncidentReporterInterface::class)) {
            $incidentReporter = new InMemoryIncidentReporter();
            $container->instance(IncidentReporterInterface::class, $incidentReporter);
            $container->instance(InMemoryIncidentReporter::class, $incidentReporter);
        }

        // Consent Manager: default to in-memory, override with database-backed via config
        if (!$container->has(ConsentManagerInterface::class)) {
            $consentManager = new InMemoryConsentManager();
            $container->instance(ConsentManagerInterface::class, $consentManager);
            $container->instance(InMemoryConsentManager::class, $consentManager);
        }

        // Data Purge Orchestrator: wire up reference purge implementations
        if (!$container->has(DataPurgeOrchestrator::class)) {
            /** @var LoggerInterface|null $purgeLogger */
            $purgeLogger = $container->has(LoggerInterface::class)
                ? $container->get(LoggerInterface::class)
                : null;

            /** @var array<string, DataPurgeInterface> $purgers */
            $purgers = [];

            // Audit log purge: uses the same log path from observability config
            if ($repository->has(ObservabilityConfig::class)) {
                /** @var ObservabilityConfig $obsConfigForPurge */
                $obsConfigForPurge = $repository->get(ObservabilityConfig::class);

                if ($obsConfigForPurge->audit->enabled) {
                    $purgers['audit_logs'] = new AuditLogPurge(
                        WritablePathGuard::resolveState($obsConfigForPurge->audit->logPath, 'observability.audit.log_path'),
                        $purgeLogger,
                    );
                }
            }

            // Session purge: uses the active session handler. The key must
            // match the retention policy category ('user_sessions' in
            // config/data_protection.php) or the orchestrator skips it.
            if ($container->has(SessionHandlerInterface::class)) {
                $purgers['user_sessions'] = new SessionPurge($container->get(SessionHandlerInterface::class));
            }

            // Build policies from DataProtectionConfig
            $dpConfig = $repository->has(DataProtectionConfig::class)
                ? $repository->get(DataProtectionConfig::class)
                : new DataProtectionConfig();

            /** @var array<string, RetentionPolicyInterface> $policies */
            $policies = [];

            foreach ($dpConfig->retention as $retentionPolicy) {
                $policies[$retentionPolicy->category] = new DefaultRetentionPolicy(
                    category: $retentionPolicy->category,
                    retentionDays: max(0, $retentionPolicy->retentionDays),
                    legalBasis: $retentionPolicy->legalBasis,
                );
            }

            $auditLogger = $container->has(AuditLoggerInterface::class)
                ? $container->get(AuditLoggerInterface::class)
                : null;

            $orchestrator = new DataPurgeOrchestrator(
                purgers: $purgers,
                policies: $policies,
                config: $dpConfig,
                auditLogger: $auditLogger,
                logger: $purgeLogger,
            );
            $container->instance(DataPurgeOrchestrator::class, $orchestrator);
        }

        // Multi-domain / subdomain routing: zero-cost when no mappings configured
        $domainConfig = $this->buildDomainConfig($configManager);
        $container->instance(DomainConfig::class, $domainConfig);
        $this->reportUnknownConfigKeys($container, 'domains', $domainConfig);

        $domainResolver = new ConfigDomainResolver($domainConfig);
        $container->instance(DomainResolverInterface::class, $domainResolver);
        $container->instance(ConfigDomainResolver::class, $domainResolver);

        $subdomainMiddleware = new SubdomainRoutingMiddleware($domainResolver);
        $container->instance(SubdomainRoutingMiddleware::class, $subdomainMiddleware);

        // Add subdomain middleware to the global pipeline (resolves domain context for routing)
        $middleware->pipe($subdomainMiddleware);

        // Named middleware aliases: allow routes to use string references
        $middlewareRegistry->alias('session', SessionMiddleware::class);
        $middlewareRegistry->alias('csrf', CsrfMiddleware::class);
        $middlewareRegistry->alias('headers', SecurityHeadersMiddleware::class);
        $middlewareRegistry->alias('subdomain', SubdomainRoutingMiddleware::class);

        // HTTP rate limiting: bind a shared-store limiter (cache-backed when a
        // cache is available, so counts persist across FPM workers; in-memory
        // otherwise) and expose the middleware as a `throttle` alias routes and
        // groups opt into. Not piped globally — throttling is per route/group by
        // design. Only wired when enabled in config/security.php.
        if ($securityConfig->rateLimit->enabled) {
            $rateLimiter = $this->buildRateLimiter($container, $securityConfig->rateLimit);
            $container->instance(RateLimiterInterface::class, $rateLimiter);

            $rateLimitMiddleware = new RateLimitMiddleware(
                $rateLimiter,
                $trustedProxy,
                RateLimitKeyStrategy::fromString($securityConfig->rateLimit->keyStrategy),
            );
            $container->instance(RateLimitMiddleware::class, $rateLimitMiddleware);
            $middlewareRegistry->alias('throttle', RateLimitMiddleware::class);
        }

        // Middleware groups: composable sets for common route profiles
        $middlewareRegistry->group('web', [
            SecurityHeadersMiddleware::class,
            SessionMiddleware::class,
            CsrfMiddleware::class,
        ]);

        $middlewareRegistry->group('api', [
            SecurityHeadersMiddleware::class,
        ]);
    }

    private function buildSessionHandler(
        SecurityConfig $securityConfig,
        ContainerInterface $container,
        ?SessionEncryption $sessionEncryption,
    ): SessionHandlerInterface {
        $sessionConfig = $securityConfig->session;

        // Honour SessionConfig::$savePath (config key `save_path`), resolved to
        // an absolute path so file sessions land in the same place under CLI,
        // PHP-FPM and long-running SAPIs. Empty config lets FileHandler fall back
        // to its built-in `var/sessions` default.
        $fileSavePath = $sessionConfig->savePath !== ''
            ? WritablePathGuard::resolveState($sessionConfig->savePath, 'security.session.save_path')
            : '';

        return match ($sessionConfig->handler) {
            'database' => $container->has(PDO::class)
                ? new DatabaseHandler(
                    $container->get(PDO::class),
                    'sessions',
                    $sessionConfig->lifetime,
                )
                : new FileHandler($fileSavePath),
            'redis' => $container->has(Redis::class)
                ? new RedisHandler(
                    $container->get(Redis::class),
                    $sessionConfig->lifetime,
                )
                : new FileHandler($fileSavePath),
            'cookie' => $sessionEncryption !== null
                ? new CookieHandler($sessionEncryption, $sessionConfig)
                : new FileHandler($fileSavePath),
            'array' => new ArrayHandler(),
            default => new FileHandler($fileSavePath),
        };
    }

    /**
     * @return list<SessionValidatorInterface>
     */
    private function buildValidators(
        SecurityConfig $securityConfig,
        HmacInterface $hmacService,
        ?MasterKey $masterKey,
        ?TrustedProxy $trustedProxy = null,
    ): array {
        $validatorConfigs = $securityConfig->session->validators;
        $validators = [];

        $uaConfig = $validatorConfigs['user_agent'] ?? [];
        if (is_array($uaConfig) && ($uaConfig['enabled'] ?? true)) {
            $mode = isset($uaConfig['mode']) && $uaConfig['mode'] === 'strict' ? 'strict' : 'normalized';
            $validators[] = new UserAgentValidator($mode);
        }

        $ipConfig = $validatorConfigs['remote_address'] ?? [];
        if (is_array($ipConfig) && ($ipConfig['enabled'] ?? false)) {
            $mode = isset($ipConfig['mode']) && $ipConfig['mode'] === 'strict' ? 'strict' : 'subnet';
            $ipv4Mask = $ipConfig['ipv4_mask'] ?? 24;
            $ipv6Mask = $ipConfig['ipv6_mask'] ?? 48;
            $validators[] = new RemoteAddressValidator($mode, $ipv4Mask, $ipv6Mask, $trustedProxy);
        }

        $fpConfig = $validatorConfigs['fingerprint'] ?? [];
        if (is_array($fpConfig) && ($fpConfig['enabled'] ?? false) && $masterKey !== null) {
            try {
                $fpKey = $masterKey->deriveSubKey(4, 'sess_fp_');
                $attributes = $fpConfig['attributes'] ?? ['accept_language', 'accept_encoding'];
                $validators[] = new FingerprintValidator($hmacService, $fpKey, $attributes);
            } catch (SodiumException) {
                // Skip fingerprint validator if key derivation fails
            }
        }

        return $validators;
    }

    private function buildCipherSuite(string $name): CipherSuiteInterface
    {
        return match ($name) {
            'aes-gcm' => new AesGcmCipherSuite(),
            default => new SodiumCipherSuite(),
        };
    }

    /**
     * Prefer the PSR-16 cache-backed limiter so counts persist across FPM
     * workers; fall back to the in-memory limiter when no cache is bound.
     */
    private function buildRateLimiter(ContainerInterface $container, RateLimitConfig $config): RateLimiterInterface
    {
        if ($container->has(CacheManagerInterface::class)) {
            /** @var CacheManagerInterface $cacheManager */
            $cacheManager = $container->get(CacheManagerInterface::class);

            return new CacheRateLimiter(
                $cacheManager->simple(),
                $config->defaultLimit,
                $config->defaultWindow,
            );
        }

        return new RateLimiter($config->defaultLimit, $config->defaultWindow);
    }

    /**
     * Build the key provider, wrapping MasterKey in CompositeKeyProvider if overrides exist.
     *
     * Supported environment variables for per-subsystem key overrides:
     * - PULSAR_ENCRYPTION_KEY (context: encrypt_)
     * - PULSAR_AUDIT_KEY (context: audit___)
     *
     * Each must be a hex-encoded raw key of the correct length for its subsystem.
     */
    private function buildKeyProvider(MasterKey $masterKey, Environment $environment): KeyProviderInterface
    {
        /** @var array<string, string> $overrideEnvMap context => env var name */
        $overrideEnvMap = [
            'encrypt_' => 'PULSAR_ENCRYPTION_KEY',
            'audit___' => 'PULSAR_AUDIT_KEY',
        ];

        /** @var array<string, string> $overrides */
        $overrides = [];

        foreach ($overrideEnvMap as $context => $envVar) {
            $hex = $environment->get($envVar);
            if ($hex !== null && $hex !== '') {
                $raw = sodium_hex2bin($hex);
                if ($raw !== '') {
                    $overrides[$context] = $raw;
                }
            }
        }

        if ($overrides === []) {
            return $masterKey;
        }

        return new CompositeKeyProvider($masterKey, $overrides);
    }

    /**
     * Load multi-domain configuration from config/domains.php.
     */
    private function buildDomainConfig(ConfigManager $configManager): DomainConfig
    {
        $configPath = $configManager->configPath();
        $environment = $configManager->environment();

        if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'domains.php')) {
            /**
             * @psalm-suppress UnresolvableInclude
             * @var mixed $data
             */
            $data = require $configPath . DIRECTORY_SEPARATOR . 'domains.php';

            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                return DomainConfig::fromArray($data, $environment);
            }
        }

        return new DomainConfig();
    }
}
