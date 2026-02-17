<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\AntiSpam\AccountAgeGate;
use Pulsar\Security\AntiSpam\AntiSpamCheckInterface;
use Pulsar\Security\AntiSpam\AntiSpamConfig;
use Pulsar\Security\AntiSpam\AntiSpamPipeline;
use Pulsar\Security\AntiSpam\AntiSpamPipelineInterface;
use Pulsar\Security\AntiSpam\ContentQualityGate;
use Pulsar\Security\AntiSpam\DuplicateDetector;
use Pulsar\Security\AntiSpam\HCaptchaVerifier;
use Pulsar\Security\AntiSpam\HoneypotDetector;
use Pulsar\Security\AntiSpam\LinkDensityChecker;
use Pulsar\Security\AntiSpam\ProofOfWorkVerifier;
use Pulsar\Security\AntiSpam\ReputationCooldown;
use Pulsar\Security\AntiSpam\TurnstileVerifier;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Wires the anti-spam pipeline and all its check implementations.
 *
 * The pipeline is consumed by CMS CommentAntiAbuseMiddleware and
 * Forum ForumAntiAbuseMiddleware via the AntiSpamPipelineInterface binding.
 */
#[Internal]
final readonly class AntiSpamWiring implements ServiceWiringInterface
{
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

        // 5. Proof of work verifier
        if ($config->proofOfWorkEnabled) {
            $checks[] = new ProofOfWorkVerifier($config->proofOfWorkPrefix);
        }

        // 6. Account age gate
        if ($config->accountAgeGateEnabled) {
            $checks[] = new AccountAgeGate($config->minAccountAgeSeconds);
        }

        // 7. Reputation cooldown (requires cache)
        if ($config->reputationCooldownEnabled && $container->has(TaggedCacheInterface::class)) {
            /** @var TaggedCacheInterface $cache */
            $cache = $container->get(TaggedCacheInterface::class);
            $checks[] = new ReputationCooldown($cache, $config->cooldownTiers);
        }

        // 8. CAPTCHA verifier (conditional on config keys being set)
        if (
            $config->captchaEnabled
            && $config->captchaSiteKey !== ''
            && $config->captchaSecretKey !== ''
            && $container->has(HttpClientInterface::class)
        ) {
            /** @var HttpClientInterface $httpClient */
            $httpClient = $container->get(HttpClientInterface::class);

            $captchaVerifier = match ($config->captchaProvider) {
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

            $checks[] = $captchaVerifier;
        }

        // Build the pipeline
        $pipeline = new AntiSpamPipeline($checks, $config->shortCircuit);
        $container->instance(AntiSpamPipeline::class, $pipeline);
        $container->instance(AntiSpamPipelineInterface::class, $pipeline);
    }

    private function loadConfig(ConfigManager $configManager): AntiSpamConfig
    {
        $configPath = $configManager->configPath();

        if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'anti-spam.php')) {
            /** @psalm-suppress UnresolvableInclude */
            $data = require $configPath . DIRECTORY_SEPARATOR . 'anti-spam.php';

            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                return AntiSpamConfig::fromArray($data);
            }
        }

        return new AntiSpamConfig();
    }
}
