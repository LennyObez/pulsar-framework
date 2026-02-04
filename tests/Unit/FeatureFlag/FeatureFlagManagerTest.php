<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\FeatureFlagManager;
use Pulsar\FeatureFlag\FlagContext;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagEvaluationReason;
use Pulsar\FeatureFlag\FlagType;
use Pulsar\FeatureFlag\Storage\InMemoryFlagStorage;

#[CoversClass(FeatureFlagManager::class)]
final class FeatureFlagManagerTest extends TestCase
{
    private InMemoryFlagStorage $storage;
    private FlagEvaluationLog $log;
    private FeatureFlagManager $manager;

    protected function setUp(): void
    {
        $this->storage = new InMemoryFlagStorage();
        $this->log = new FlagEvaluationLog();
        $this->manager = new FeatureFlagManager($this->storage, $this->log, defaultState: false);
    }

    #[Test]
    public function booleanFlagReturnsTrueWhenEnabled(): void
    {
        $this->storage->set(new FlagDefinition(
            name: 'feature-on',
            enabled: true,
            type: FlagType::Boolean,
        ));

        self::assertTrue($this->manager->isEnabled('feature-on'));
    }

    #[Test]
    public function disabledFlagReturnsFalse(): void
    {
        $this->storage->set(new FlagDefinition(
            name: 'feature-off',
            enabled: false,
            type: FlagType::Boolean,
        ));

        self::assertFalse($this->manager->isEnabled('feature-off'));

        $evaluation = $this->manager->evaluate('feature-off');
        self::assertSame(FlagEvaluationReason::FlagDisabled, $evaluation->reason);
    }

    #[Test]
    public function notFoundFlagReturnsDefaultState(): void
    {
        self::assertFalse($this->manager->isEnabled('nonexistent'));

        $evaluation = $this->manager->evaluate('nonexistent');
        self::assertSame(FlagEvaluationReason::FlagNotFound, $evaluation->reason);
        self::assertFalse($evaluation->result);
    }

    #[Test]
    public function percentageFlagIsDeterministicForSameUser(): void
    {
        $this->storage->set(new FlagDefinition(
            name: 'rollout',
            enabled: true,
            type: FlagType::Percentage,
            percentage: 50,
        ));

        $context = new FlagContext(userId: 'user-42');

        // Evaluate multiple times to verify determinism
        $firstResult = $this->manager->isEnabled('rollout', $context);
        $secondResult = $this->manager->isEnabled('rollout', $context);
        $thirdResult = $this->manager->isEnabled('rollout', $context);

        self::assertSame($firstResult, $secondResult);
        self::assertSame($secondResult, $thirdResult);

        // Verify it matches the expected hash-based calculation
        $hash = crc32('rollout' . 'user-42');
        $bucket = (($hash % 100) + 100) % 100;
        $expectedResult = $bucket < 50;

        self::assertSame($expectedResult, $firstResult);
    }

    #[Test]
    public function contextualFlagMatchesTenant(): void
    {
        $this->storage->set(new FlagDefinition(
            name: 'tenant-feature',
            enabled: true,
            type: FlagType::Contextual,
            allowedTenants: ['acme', 'globex'],
        ));

        $context = new FlagContext(tenantId: 'acme');

        self::assertTrue($this->manager->isEnabled('tenant-feature', $context));

        $evaluation = $this->manager->evaluate('tenant-feature', $context);
        self::assertSame(FlagEvaluationReason::TenantMatch, $evaluation->reason);
    }

    #[Test]
    public function contextualFlagMatchesUser(): void
    {
        $this->storage->set(new FlagDefinition(
            name: 'user-feature',
            enabled: true,
            type: FlagType::Contextual,
            allowedUsers: ['user-1', 'user-2'],
        ));

        $context = new FlagContext(userId: 'user-1');

        self::assertTrue($this->manager->isEnabled('user-feature', $context));

        $evaluation = $this->manager->evaluate('user-feature', $context);
        self::assertSame(FlagEvaluationReason::UserMatch, $evaluation->reason);
    }

    #[Test]
    public function contextualFlagMatchesEnvironment(): void
    {
        $this->storage->set(new FlagDefinition(
            name: 'env-feature',
            enabled: true,
            type: FlagType::Contextual,
            allowedEnvironments: ['production', 'staging'],
        ));

        $context = new FlagContext(environment: 'production');

        self::assertTrue($this->manager->isEnabled('env-feature', $context));

        $evaluation = $this->manager->evaluate('env-feature', $context);
        self::assertSame(FlagEvaluationReason::EnvironmentMatch, $evaluation->reason);
    }

    #[Test]
    public function contextualFlagReturnsDefaultWhenNoContextMatches(): void
    {
        $this->storage->set(new FlagDefinition(
            name: 'restricted-feature',
            enabled: true,
            type: FlagType::Contextual,
            allowedTenants: ['acme'],
            allowedUsers: ['user-1'],
            allowedEnvironments: ['production'],
        ));

        $context = new FlagContext(
            tenantId: 'other-tenant',
            userId: 'user-99',
            environment: 'development',
        );

        self::assertFalse($this->manager->isEnabled('restricted-feature', $context));

        $evaluation = $this->manager->evaluate('restricted-feature', $context);
        self::assertSame(FlagEvaluationReason::DefaultState, $evaluation->reason);
    }

    #[Test]
    public function evaluationsAreRecordedInLog(): void
    {
        $this->storage->set(new FlagDefinition(
            name: 'logged-flag',
            enabled: true,
            type: FlagType::Boolean,
        ));

        self::assertSame(0, $this->log->count());

        $this->manager->isEnabled('logged-flag');
        self::assertSame(1, $this->log->count());

        $this->manager->isEnabled('logged-flag');
        self::assertSame(2, $this->log->count());

        $entries = $this->log->forFlag('logged-flag');
        self::assertCount(2, $entries);
        self::assertSame('logged-flag', $entries[0]->flagName);
        self::assertTrue($entries[0]->result);
        self::assertSame(FlagEvaluationReason::FlagEnabled, $entries[0]->reason);
    }
}
