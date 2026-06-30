<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;

use function bin2hex;
use function is_int;
use function is_string;
use function sodium_crypto_generichash;
use function sprintf;
use function time;

/**
 * Enforces cooldown periods between submissions based on user reputation tier.
 *
 * Default tiers:
 * - new: 60 seconds between submissions
 * - established: 10 seconds
 * - moderator: 0 seconds (no cooldown)
 *
 * Tracks last submission time per user/IP in the cache layer.
 */
#[Internal(reason: 'Use ReputationCooldownInterface')]
final readonly class ReputationCooldown implements ReputationCooldownInterface
{
    private const string CACHE_TAG = 'antispam_cooldown';

    /**
     * @param array<string, int> $tiers Cooldown seconds per reputation tier
     */
    public function __construct(
        private TaggedCacheInterface $cache,
        private array $tiers = ['new' => 60, 'established' => 10, 'moderator' => 0],
    ) {}

    #[Override]
    public function name(): string
    {
        return 'reputation_cooldown';
    }

    #[Override]
    public function check(AntiSpamContext $context): AntiSpamCheckResult
    {
        $cooldownSeconds = $this->tiers[$context->reputationTier] ?? $this->tiers['new'] ?? 60;

        if ($cooldownSeconds <= 0) {
            return AntiSpamCheckResult::pass($this->name());
        }

        // Hash the identity into the key: userId may be arbitrary (e.g. an
        // email) and ':' / other PSR-6 reserved characters would be rejected by
        // the tagged cache. A generichash digest is hex, so the key is always
        // valid regardless of the identity's shape.
        $identity = $context->userId ?? $context->ipHash;
        $cacheKey = sprintf('antispam_cooldown.%s', bin2hex(sodium_crypto_generichash($identity)));

        /** @var mixed $lastSubmission */
        $lastSubmission = $this->cache->get($cacheKey);

        if ($lastSubmission !== null && (is_int($lastSubmission) || is_string($lastSubmission))) {
            $lastTime = (int) $lastSubmission;
            $elapsed = time() - $lastTime;

            if ($elapsed < $cooldownSeconds) {
                $remaining = $cooldownSeconds - $elapsed;

                return AntiSpamCheckResult::fail(
                    $this->name(),
                    15,
                    sprintf(
                        'Cooldown active: please wait %d seconds before submitting again',
                        $remaining,
                    ),
                );
            }
        }

        // Record this submission timestamp
        $this->cache->set($cacheKey, (string) time(), [self::CACHE_TAG], $cooldownSeconds);

        return AntiSpamCheckResult::pass($this->name());
    }
}
