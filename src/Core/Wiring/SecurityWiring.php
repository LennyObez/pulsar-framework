<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use PDO;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManager;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Middleware\SecurityHeadersMiddleware;
use Pulsar\Security\Session\Flash\FlashBag;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\Handler\CookieHandler;
use Pulsar\Security\Session\Handler\FileHandler;
use Pulsar\Security\Session\Handler\SessionHandlerInterface;
use Pulsar\Security\Session\Session;
use Pulsar\Security\Session\SessionEncryption;
use Pulsar\Security\Session\SessionInterface;
use Pulsar\Security\Session\SessionManager;
use Pulsar\Security\Session\SessionMiddleware;
use Pulsar\Security\Session\Validator\FingerprintValidator;
use Pulsar\Security\Session\Validator\RemoteAddressValidator;
use Pulsar\Security\Session\Validator\SessionValidatorInterface;
use Pulsar\Security\Session\Validator\UserAgentValidator;
use Random\Randomizer;
use Redis;
use SodiumException;

use function dirname;
use function is_array;

#[Internal]
final readonly class SecurityWiring implements ServiceWiringInterface
{
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

        // HmacService adapter — always available (no key required, delegates to static Hmac methods)
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
                $container->instance(KeyProviderInterface::class, $masterKey);

                $encryptor = Encryptor::fromMasterKey($masterKey);
                $container->instance(Encryptor::class, $encryptor);
                $container->instance(EncryptorInterface::class, $encryptor);

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
                        $frameworkCache = new FrameworkCache(dirname($configPath), $masterKey, $hmacService, $encrypt, $encrypt ? $encryptor : null);
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
                    $auditSink = new AuditFileSink($obsConfig->audit->logPath);
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
            } catch (SecurityException | SodiumException) {
                // Master key is invalid or sodium operation failed — skip crypto/audit registration.
                // Session, CSRF, and headers still work without it.
                $masterKey = null;
                $sessionEncryption = null;
            }
        }

        // Session — build handler, validators, and manager
        $sessionHandler = $this->buildSessionHandler($securityConfig, $container, $sessionEncryption);
        $container->instance(SessionHandlerInterface::class, $sessionHandler);

        $validators = $this->buildValidators($securityConfig, $hmacService, $masterKey);

        $sessionManager = new SessionManager(
            $sessionHandler,
            $securityConfig->session,
            $validators,
            $sessionEncryption,
        );
        $container->instance(SessionManager::class, $sessionManager);
        $container->instance(SessionInterface::class, $sessionManager);

        // Legacy Session alias for backward compatibility with existing SessionGuard
        $legacySession = new Session($securityConfig->session);
        $container->instance(Session::class, $legacySession);

        // Flash messages
        $flashBag = new FlashBag($sessionManager);
        $container->instance(FlashBag::class, $flashBag);

        // Session middleware
        $sessionMiddleware = new SessionMiddleware($sessionManager, $flashBag);
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

        $csrfMiddleware = new CsrfMiddleware($csrfTokenManager, $securityConfig->csrf);
        $container->instance(CsrfMiddleware::class, $csrfMiddleware);

        // Security Headers
        $headersMiddleware = new SecurityHeadersMiddleware($securityConfig->headers);
        $container->instance(SecurityHeadersMiddleware::class, $headersMiddleware);
    }

    private function buildSessionHandler(
        SecurityConfig $securityConfig,
        ContainerInterface $container,
        ?SessionEncryption $sessionEncryption,
    ): SessionHandlerInterface {
        $sessionConfig = $securityConfig->session;

        return match ($sessionConfig->handler) {
            'database' => $container->has(PDO::class)
                ? new \Pulsar\Security\Session\Handler\DatabaseHandler(
                    $container->get(PDO::class),
                    'sessions',
                    $sessionConfig->lifetime,
                )
                : new FileHandler(),
            'redis' => $container->has(Redis::class)
                ? new \Pulsar\Security\Session\Handler\RedisHandler(
                    $container->get(Redis::class),
                    $sessionConfig->lifetime,
                )
                : new FileHandler(),
            'cookie' => $sessionEncryption !== null
                ? new CookieHandler($sessionEncryption, $sessionConfig)
                : new FileHandler(),
            'array' => new ArrayHandler(),
            default => new FileHandler(),
        };
    }

    /**
     * @return list<SessionValidatorInterface>
     */
    private function buildValidators(
        SecurityConfig $securityConfig,
        HmacInterface $hmacService,
        ?MasterKey $masterKey,
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
            $validators[] = new RemoteAddressValidator($mode, $ipv4Mask, $ipv6Mask);
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
}
