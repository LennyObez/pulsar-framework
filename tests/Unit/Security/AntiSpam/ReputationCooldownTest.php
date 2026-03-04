<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\ReputationCooldown;

use function time;

#[CoversClass(ReputationCooldown::class)]
final class ReputationCooldownTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cacheStore = [];

    private TaggedCacheInterface $cache;

    protected function setUp(): void
    {
        $this->cacheStore = [];

        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturnCallback(
            function (string $key): mixed {
                /** @var array<string, mixed> $store */
                $store = $this->cacheStore;

                return $store[$key] ?? null;
            },
        );
        $cache->method('set')->willReturnCallback(
            function (string $key, mixed $value): bool {
                $this->cacheStore[$key] = $value;

                return true;
            },
        );

        $this->cache = $cache;
    }

    #[Test]
    public function nameReturnsReputationCooldown(): void
    {
        $cooldown = new ReputationCooldown($this->cache);
        self::assertSame('reputation_cooldown', $cooldown->name());
    }

    #[Test]
    public function passesForFirstSubmission(): void
    {
        $cooldown = new ReputationCooldown($this->cache);
        $context = new AntiSpamContext(
            body: 'First post',
            ipHash: 'ip1',
            reputationTier: 'new',
        );

        $result = $cooldown->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function failsForRapidSecondSubmission(): void
    {
        $cooldown = new ReputationCooldown($this->cache, ['new' => 60, 'established' => 10, 'moderator' => 0]);

        $context = new AntiSpamContext(
            body: 'Post',
            ipHash: 'ip1',
            reputationTier: 'new',
        );

        // First submission — passes and records
        $cooldown->check($context);

        // Immediate second submission — should fail
        $result = $cooldown->check($context);

        self::assertFalse($result->passed);
        self::assertStringContainsString('Cooldown', $result->reason ?? '');
    }

    #[Test]
    public function moderatorHasNoCooldown(): void
    {
        $cooldown = new ReputationCooldown($this->cache, ['new' => 60, 'established' => 10, 'moderator' => 0]);

        $context = new AntiSpamContext(
            body: 'Mod post',
            ipHash: 'ip1',
            userId: 'mod-1',
            reputationTier: 'moderator',
        );

        // Both submissions should pass
        $result1 = $cooldown->check($context);
        $result2 = $cooldown->check($context);

        self::assertTrue($result1->passed);
        self::assertTrue($result2->passed);
    }

    #[Test]
    public function usesUserIdWhenAuthenticated(): void
    {
        $cooldown = new ReputationCooldown($this->cache, ['new' => 60]);

        $context = new AntiSpamContext(
            body: 'Post',
            ipHash: 'ip1',
            userId: 'user-1',
            reputationTier: 'new',
        );

        $cooldown->check($context);

        // Different IP, same user — should still be in cooldown
        $contextNewIp = new AntiSpamContext(
            body: 'Post',
            ipHash: 'ip2',
            userId: 'user-1',
            reputationTier: 'new',
        );

        $result = $cooldown->check($contextNewIp);

        self::assertFalse($result->passed);
    }

    #[Test]
    public function usesIpHashForAnonymous(): void
    {
        $cooldown = new ReputationCooldown($this->cache, ['new' => 60]);

        $context = new AntiSpamContext(
            body: 'Anon post',
            ipHash: 'ip1',
            reputationTier: 'new',
        );

        $cooldown->check($context);

        // Different IP for anonymous — should pass
        $contextNewIp = new AntiSpamContext(
            body: 'Anon post',
            ipHash: 'ip2',
            reputationTier: 'new',
        );

        $result = $cooldown->check($contextNewIp);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function passesAfterCooldownExpires(): void
    {
        $cooldown = new ReputationCooldown($this->cache, ['new' => 5]);

        $context = new AntiSpamContext(
            body: 'Post',
            ipHash: 'ip1',
            reputationTier: 'new',
        );

        // Simulate first submission 10 seconds ago
        $this->cacheStore['antispam_cooldown:ip1'] = (string) (time() - 10);

        $result = $cooldown->check($context);

        self::assertTrue($result->passed);
    }

    #[Test]
    public function unknownTierFallsBackToNewTier(): void
    {
        $cooldown = new ReputationCooldown($this->cache, ['new' => 60, 'established' => 10]);

        $context = new AntiSpamContext(
            body: 'Post',
            ipHash: 'ip1',
            reputationTier: 'unknown_tier',
        );

        $cooldown->check($context);

        // Should use 'new' tier (60s), so rapid second post fails
        $result = $cooldown->check($context);

        self::assertFalse($result->passed);
    }
}
