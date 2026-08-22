<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Security\AntiSpam;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Core\Wiring\CacheWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\AntiSpamPipeline;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeService;
use Pulsar\Security\Crypto\MasterKey;
use ReflectionProperty;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function str_repeat;
use function sys_get_temp_dir;
use function var_export;

/**
 * Wiring order is load-bearing here: CacheWiring must bind TaggedCacheInterface
 * before AntiSpamWiring runs, or the tag-aware features degrade to no-ops. They
 * degrade silently — configuration still reads as enabled — so only a full boot
 * can tell an active feature from an inert one.
 */
final class CacheBackedAntiSpamFeaturesTest extends TestCase
{
    /**
     * @param array<string, mixed> $antiSpam
     */
    private function boot(array $antiSpam, bool $withMasterKey = false): Container
    {
        $configPath = sys_get_temp_dir() . '/pulsar_antispam_cache_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/cache.php', '<?php return ["enabled" => true];');
        file_put_contents($configPath . '/anti-spam.php', '<?php return ' . var_export($antiSpam, true) . ';');

        $configManager = new ConfigManager($configPath);
        // Register the anti-spam config loader before load(), as the Kernel does,
        // so AntiSpamConfigSet is built into the repository.
        ConfigLoaderRegistrar::register($configManager, [new AntiSpamWiring()]);
        $configManager->load();

        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        if ($withMasterKey) {
            $container->instance(MasterKey::class, MasterKey::fromHex(str_repeat('ab', 32)));
        }

        // Same order as the Kernel: cache first, then anti-spam.
        new CacheWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);
        new AntiSpamWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        return $container;
    }

    #[Test]
    public function duplicateDetectorIsActiveWhenCacheIsEnabled(): void
    {
        $container = $this->boot([
            // Only the cache-backed duplicate detector, so the signal is isolated.
            'honeypot_enabled' => false,
            'link_density_enabled' => false,
            'content_quality_enabled' => false,
            'reputation_cooldown_enabled' => false,
            'captcha_enabled' => false,
            'duplicate_detection_enabled' => true,
        ]);

        $pipeline = $container->get(AntiSpamPipeline::class);
        self::assertInstanceOf(AntiSpamPipeline::class, $pipeline);

        $context = new AntiSpamContext(
            body: 'A perfectly ordinary human comment.',
            ipHash: 'ip-abc',
        );

        // First submission is novel; the duplicate detector records it.
        $first = $pipeline->evaluate($context);
        self::assertNotContains('duplicate', $first->failedChecks());

        // Re-submitting the same body from the same IP must now be flagged —
        // which can only happen if the detector was wired with a tagged cache.
        $second = $pipeline->evaluate($context);
        self::assertContains('duplicate', $second->failedChecks());
        self::assertFalse($second->passed);
    }

    #[Test]
    public function managedChallengeSingleUseCacheIsActiveWhenCacheIsEnabled(): void
    {
        $container = $this->boot([
            'captcha_enabled' => true,
            'captcha_provider' => 'managed',
        ], withMasterKey: true);

        self::assertTrue($container->has(TaggedCacheInterface::class));
        self::assertTrue($container->has(ManagedChallengeService::class));

        // Single-use replay protection needs somewhere to record that a token
        // was spent. With a null cache the service still answers, but a solved
        // token can be replayed for the whole of its TTL — so the presence of
        // the cache is the assertion, not the service being resolvable.
        $service = $container->get(ManagedChallengeService::class);
        $cache = new ReflectionProperty(ManagedChallengeService::class, 'cache')->getValue($service);
        self::assertNotNull($cache);
    }
}
