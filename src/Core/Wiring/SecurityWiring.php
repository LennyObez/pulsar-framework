<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use function dirname;

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
use Pulsar\Security\Session\Session;
use Pulsar\Security\Session\SessionInterface;
use Random\Randomizer;
use SodiumException;

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

        // Session
        $session = new Session($securityConfig->session);
        $container->instance(Session::class, $session);
        $container->instance(SessionInterface::class, $session);

        // CSRF
        /** @var Randomizer $randomizer */
        $randomizer = $container->get(Randomizer::class);
        $csrfTokenManager = new CsrfTokenManager($session, $securityConfig->csrf, $randomizer);
        $container->instance(CsrfTokenManager::class, $csrfTokenManager);
        $container->instance(CsrfTokenManagerInterface::class, $csrfTokenManager);

        $csrfMiddleware = new CsrfMiddleware($csrfTokenManager, $securityConfig->csrf);
        $container->instance(CsrfMiddleware::class, $csrfMiddleware);

        // Security Headers
        $headersMiddleware = new SecurityHeadersMiddleware($securityConfig->headers);
        $container->instance(SecurityHeadersMiddleware::class, $headersMiddleware);

        // HmacService adapter — always available (no key required, delegates to static Hmac methods)
        $hmacService = new HmacService();
        $container->instance(HmacInterface::class, $hmacService);

        // Crypto + Audit (only if master key is available)
        $masterKeyHex = $environment->get('PULSAR_MASTER_KEY');

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
            }
        }
    }
}
