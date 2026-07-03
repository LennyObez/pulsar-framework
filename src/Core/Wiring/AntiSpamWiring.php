<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\OptionalBinding;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Routing\Router;
use Pulsar\Security\AntiSpam\AccountAgeGate;
use Pulsar\Security\AntiSpam\AntiSpamCheckInterface;
use Pulsar\Security\AntiSpam\AntiSpamConfig;
use Pulsar\Security\AntiSpam\AntiSpamPipeline;
use Pulsar\Security\AntiSpam\AntiSpamPipelineInterface;
use Pulsar\Security\AntiSpam\Behavior\BehavioralSignalsCheck;
use Pulsar\Security\AntiSpam\Behavior\BehaviorCollectorRenderer;
use Pulsar\Security\AntiSpam\Behavior\BehaviorFeatureSink;
use Pulsar\Security\AntiSpam\Behavior\BehaviorScorerInterface;
use Pulsar\Security\AntiSpam\Behavior\HeuristicScorer;
use Pulsar\Security\AntiSpam\Behavior\NullBehaviorFeatureSink;
use Pulsar\Security\AntiSpam\CaptchaVerifierInterface;
use Pulsar\Security\AntiSpam\ContentQualityGate;
use Pulsar\Security\AntiSpam\DuplicateDetector;
use Pulsar\Security\AntiSpam\HCaptchaVerifier;
use Pulsar\Security\AntiSpam\HoneypotDetector;
use Pulsar\Security\AntiSpam\LinkDensityChecker;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeAssetController;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeRefreshController;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeRenderer;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeService;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeVerifier;
use Pulsar\Security\AntiSpam\ReputationCooldown;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapCheck;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapRenderer;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapService;
use Pulsar\Security\AntiSpam\TurnstileVerifier;
use Pulsar\Security\Crypto\MasterKey;
use SodiumException;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;
use const SODIUM_CRYPTO_AUTH_KEYBYTES;

/**
 * Wires the anti-spam pipeline and all its check implementations.
 *
 * The pipeline is consumed by CMS CommentAntiAbuseMiddleware and
 * Forum ForumAntiAbuseMiddleware via the AntiSpamPipelineInterface binding.
 */
#[Internal]
final readonly class AntiSpamWiring implements ServiceWiringInterface, DescribesWiring
{
    public function describeWiring(): WiringContract
    {
        return new WiringContract(
            component: 'anti-spam',
            configClass: AntiSpamConfig::class,
            configFile: 'anti-spam.php',
            provides: [
                AntiSpamConfig::class,
                AntiSpamPipeline::class,
                AntiSpamPipelineInterface::class,
            ],
            optional: [
                new OptionalBinding(
                    binding: TaggedCacheInterface::class,
                    feature: 'duplicate detection, reputation cooldowns, and managed-challenge single-use replay protection',
                    fix: 'Enable the cache (CacheWiring binds TaggedCacheInterface when cache is enabled).',
                    security: true,
                ),
                new OptionalBinding(
                    binding: MasterKey::class,
                    feature: 'managed-challenge and time-trap token signing',
                    fix: 'Configure PULSAR_MASTER_KEY.',
                    security: true,
                ),
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
        // Load config from file if available
        $config = $this->loadConfig($configManager);
        $container->instance(AntiSpamConfig::class, $config);

        /** @var LoggerInterface $logger */
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : new NullLogger();

        // Build the ordered list of checks based on config
        /** @var list<AntiSpamCheckInterface> $checks */
        $checks = [];

        // 1. Honeypot detector
        if ($config->honeypotEnabled) {
            $checks[] = new HoneypotDetector($config->honeypotFieldName);
        }

        // 2. Duplicate detector (requires cache)
        if ($config->duplicateDetectionEnabled && $container->has(TaggedCacheInterface::class)) {
            /** @var TaggedCacheInterface $cache */
            $cache = $container->get(TaggedCacheInterface::class);
            $checks[] = new DuplicateDetector(
                $cache,
                $config->duplicateWindowSeconds,
                $config->duplicateSimilarityThreshold,
            );
        }

        // 3. Link density checker
        if ($config->linkDensityEnabled) {
            $checks[] = new LinkDensityChecker($config->maxLinkDensity);
        }

        // 4. Content quality gate
        if ($config->contentQualityEnabled) {
            $checks[] = new ContentQualityGate(
                $config->minContentLength,
                $config->maxUppercaseRatio,
                $config->maxRepeatedCharRatio,
            );
        }

        // 5. Account age gate
        if ($config->accountAgeGateEnabled) {
            $checks[] = new AccountAgeGate($config->minAccountAgeSeconds);
        }

        // 6. Reputation cooldown (requires cache)
        if ($config->reputationCooldownEnabled && $container->has(TaggedCacheInterface::class)) {
            /** @var TaggedCacheInterface $cache */
            $cache = $container->get(TaggedCacheInterface::class);
            $checks[] = new ReputationCooldown($cache, $config->cooldownTiers);
        }

        // 7. CAPTCHA verifier
        if ($config->captchaEnabled) {
            if ($config->captchaProvider === 'managed') {
                // Self-hosted managed challenge: no keys, no external service.
                $managedVerifier = $this->wireManagedChallenge($container, $config, $logger, $router);

                if ($managedVerifier !== null) {
                    $checks[] = $managedVerifier;
                }
            } elseif (
                $config->captchaSiteKey !== ''
                && $config->captchaSecretKey !== ''
                && $container->has(HttpClientInterface::class)
            ) {
                /** @var HttpClientInterface $httpClient */
                $httpClient = $container->get(HttpClientInterface::class);

                $checks[] = match ($config->captchaProvider) {
                    'turnstile' => new TurnstileVerifier(
                        $httpClient,
                        $logger,
                        $config->captchaSiteKey,
                        $config->captchaSecretKey,
                    ),
                    default => new HCaptchaVerifier(
                        $httpClient,
                        $logger,
                        $config->captchaSiteKey,
                        $config->captchaSecretKey,
                    ),
                };
            }
        }

        // 8. Time-trap (no-JS form-fill-timing). Opt-in; requires the master key.
        if ($config->timeTrapEnabled) {
            $timeTrapCheck = $this->wireTimeTrap($container, $config, $logger);

            if ($timeTrapCheck !== null) {
                $checks[] = $timeTrapCheck;
            }
        }

        // 9. Behavioural signals (score-only, self-hosted). Opt-in.
        if ($config->behaviorEnabled) {
            $checks[] = $this->wireBehavioralSignals($container, $config, $router);
        }

        // Build the pipeline
        $pipeline = new AntiSpamPipeline($checks, $config->shortCircuit);
        $container->instance(AntiSpamPipeline::class, $pipeline);
        $container->instance(AntiSpamPipelineInterface::class, $pipeline);
    }

    /**
     * Wire the self-hosted managed-challenge captcha (no external service).
     *
     * Derives a dedicated signing sub-key from the master key, registers the
     * same-origin widget/worker/pow asset routes, the renderer (global +
     * container) backing the @shield directive, and returns the verifier for
     * the pipeline. Returns null — disabling the provider rather than failing
     * boot — when no master key is configured or key derivation fails.
     */
    private function wireManagedChallenge(
        ContainerInterface $container,
        AntiSpamConfig $config,
        LoggerInterface $logger,
        Router $router,
    ): ?ManagedChallengeVerifier {
        if (!$container->has(MasterKey::class)) {
            $logger->warning(
                'Managed challenge captcha requires a configured PULSAR_MASTER_KEY; provider disabled.',
            );

            return null;
        }

        /** @var MasterKey $masterKey */
        $masterKey = $container->get(MasterKey::class);

        try {
            // Sub-key id 16, domain-separated context (8 chars) for anti-spam signing.
            $signingKey = $masterKey->deriveSubKey(16, 'antispam');
        } catch (SodiumException $e) {
            $logger->error('Managed challenge signing key derivation failed; provider disabled.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $cache = $container->has(TaggedCacheInterface::class)
            ? $container->get(TaggedCacheInterface::class)
            : null;
        /** @var TaggedCacheInterface|null $cache */

        $service = new ManagedChallengeService(
            $signingKey,
            $config->managedChallengeBits,
            $config->managedChallengeTtlSeconds,
            $cache,
            $logger,
        );
        $container->instance(ManagedChallengeService::class, $service);

        // Same-origin asset routes keep the widget CSP `script-src 'self'` clean.
        $base = '/_pulsar/anti-spam';
        $container->instance(ManagedChallengeAssetController::class, new ManagedChallengeAssetController());
        $router->get($base . '/managed-challenge.js', [ManagedChallengeAssetController::class, 'widget'], 'pulsar.anti_spam.mc.widget');
        $router->get($base . '/managed-challenge.worker.js', [ManagedChallengeAssetController::class, 'worker'], 'pulsar.anti_spam.mc.worker');
        $router->get($base . '/managed-challenge.pow.js', [ManagedChallengeAssetController::class, 'pow'], 'pulsar.anti_spam.mc.pow');

        // Same-origin refresh endpoint for the widget's silent re-mint. Reuses
        // the tagged cache (when bound) for a best-effort per-IP rate cap.
        $refreshPath = $base . '/managed-challenge/refresh';
        $container->instance(
            ManagedChallengeRefreshController::class,
            new ManagedChallengeRefreshController($service, $cache),
        );
        $router->get($refreshPath, [ManagedChallengeRefreshController::class, 'refresh'], 'pulsar.anti_spam.mc.refresh');

        $translator = null;

        if ($container->has(TranslatorInterface::class)) {
            /** @var TranslatorInterface $translator */
            $translator = $container->get(TranslatorInterface::class);
        }

        $renderer = new ManagedChallengeRenderer(
            $service,
            $config->managedChallengeFieldName,
            $base . '/managed-challenge.js',
            $base . '/managed-challenge.worker.js',
            $refreshPath,
            $config->managedChallengeTtlSeconds,
            $translator,
        );
        $container->instance(ManagedChallengeRenderer::class, $renderer);
        ManagedChallengeRenderer::setGlobalInstance($renderer);

        $verifier = new ManagedChallengeVerifier($service);

        // Expose the verifier on the #[Api] CaptchaVerifierInterface so extensions
        // (e.g. the CMS contact-form spam detector) can validate a solved managed
        // challenge without importing the module-private ManagedChallenge internals.
        $container->instance(CaptchaVerifierInterface::class, $verifier);

        return $verifier;
    }

    /**
     * Wire the no-JS time-trap check (and its renderer backing @timetrap/@shield).
     *
     * Derives a dedicated signing sub-key from the master key — sub-key id 17,
     * distinct from the managed challenge's id 16 so the two features never share
     * key material — and registers the renderer (global + container). Returns
     * null, disabling the check rather than failing boot, when no master key is
     * configured or key derivation fails.
     */
    private function wireTimeTrap(
        ContainerInterface $container,
        AntiSpamConfig $config,
        LoggerInterface $logger,
    ): ?TimeTrapCheck {
        if (!$container->has(MasterKey::class)) {
            $logger->warning(
                'Time-trap anti-spam check requires a configured PULSAR_MASTER_KEY; check disabled.',
            );

            return null;
        }

        /** @var MasterKey $masterKey */
        $masterKey = $container->get(MasterKey::class);

        try {
            $signingKey = $masterKey->deriveSubKey(17, 'antispam', SODIUM_CRYPTO_AUTH_KEYBYTES);
        } catch (SodiumException $e) {
            $logger->error('Time-trap signing key derivation failed; check disabled.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $service = new TimeTrapService($signingKey);
        $container->instance(TimeTrapService::class, $service);

        $renderer = new TimeTrapRenderer($service, $config->timeTrapFieldName);
        $container->instance(TimeTrapRenderer::class, $renderer);
        TimeTrapRenderer::setGlobalInstance($renderer);

        return new TimeTrapCheck(
            $service,
            $config->timeTrapFieldName,
            $config->timeTrapMinSeconds,
            $config->timeTrapMaxSeconds,
        );
    }

    /**
     * Wire the score-only behavioural-signals check.
     *
     * Uses an application-bound scorer/sink when present, otherwise the default
     * HeuristicScorer (config-tuned weights) and the zero-retention NullSink.
     */
    private function wireBehavioralSignals(
        ContainerInterface $container,
        AntiSpamConfig $config,
        Router $router,
    ): BehavioralSignalsCheck {
        $scorer = $container->has(BehaviorScorerInterface::class)
            ? $container->get(BehaviorScorerInterface::class)
            : HeuristicScorer::fromWeights($config->behaviorWeights);
        /** @var BehaviorScorerInterface $scorer */
        $sink = $container->has(BehaviorFeatureSink::class)
            ? $container->get(BehaviorFeatureSink::class)
            : new NullBehaviorFeatureSink();
        /** @var BehaviorFeatureSink $sink */

        // Same-origin collector asset + the hidden-field renderer backing @shield.
        $base = '/_pulsar/anti-spam';
        $assetController = $container->has(ManagedChallengeAssetController::class)
            ? $container->get(ManagedChallengeAssetController::class)
            : new ManagedChallengeAssetController();
        /** @var ManagedChallengeAssetController $assetController */
        $container->instance(ManagedChallengeAssetController::class, $assetController);
        $scriptUrl = $base . '/behavior-collector.js';
        $router->get($scriptUrl, [ManagedChallengeAssetController::class, 'behaviorCollector'], 'pulsar.anti_spam.behavior.collector');

        $renderer = new BehaviorCollectorRenderer($config->behaviorFieldName, $scriptUrl);
        $container->instance(BehaviorCollectorRenderer::class, $renderer);
        BehaviorCollectorRenderer::setGlobalInstance($renderer);

        return new BehavioralSignalsCheck($scorer, $sink, $config->behaviorFieldName);
    }

    private function loadConfig(ConfigManager $configManager): AntiSpamConfig
    {
        $configPath = $configManager->configPath();

        if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'anti-spam.php')) {
            /**
             * @psalm-suppress UnresolvableInclude
             * @var mixed $data
             */
            $data = require $configPath . DIRECTORY_SEPARATOR . 'anti-spam.php';

            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                return AntiSpamConfig::fromArray($data);
            }
        }

        return new AntiSpamConfig();
    }
}
