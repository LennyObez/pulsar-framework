<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\FeatureFlagManager;
use Pulsar\FeatureFlag\FlagContext;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagEvaluation;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagEvaluationReason;
use Pulsar\FeatureFlag\FlagType;
use Pulsar\FeatureFlag\Storage\InMemoryFlagStorage;

#[CoversClass(FeatureFlagManager::class)]
#[CoversClass(FlagEvaluation::class)]
#[CoversClass(FlagContext::class)]
final class FeatureFlagManagerCoverageTest extends TestCase
{
    private InMemoryFlagStorage $storage;
    private FlagEvaluationLog $log;

    protected function setUp(): void
    {
        $this->storage = new InMemoryFlagStorage();
        $this->log = new FlagEvaluationLog();
    }

    #[Test]
    public function defaultStateTrueReturnsForUnknownFlag(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log, defaultState: true);

        self::assertTrue($manager->isEnabled('nonexistent'));

        $eval = $manager->evaluate('nonexistent');
        self::assertTrue($eval->result);
        self::assertSame(FlagEvaluationReason::FlagNotFound, $eval->reason);
    }

    #[Test]
    public function defaultStateTrueReturnsForContextualNoMatch(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log, defaultState: true);

        $this->storage->set(new FlagDefinition(
            name: 'ctx-flag',
            enabled: true,
            type: FlagType::Contextual,
            allowedTenants: ['acme'],
        ));

        // Context does not match any allowed tenant
        $context = new FlagContext(tenantId: 'other');
        $eval = $manager->evaluate('ctx-flag', $context);

        self::assertTrue($eval->result);
        self::assertSame(FlagEvaluationReason::DefaultState, $eval->reason);
    }

    #[Test]
    public function percentageZeroAlwaysExcludes(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'zero-pct',
            enabled: true,
            type: FlagType::Percentage,
            percentage: 0,
        ));

        // Test with multiple users
        for ($i = 0; $i < 20; $i++) {
            $result = $manager->isEnabled('zero-pct', new FlagContext(userId: "user-{$i}"));
            self::assertFalse($result, "User user-{$i} should be excluded at 0%");
        }
    }

    #[Test]
    public function percentageHundredAlwaysIncludes(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'full-rollout',
            enabled: true,
            type: FlagType::Percentage,
            percentage: 100,
        ));

        // Test with multiple users
        for ($i = 0; $i < 20; $i++) {
            $result = $manager->isEnabled('full-rollout', new FlagContext(userId: "user-{$i}"));
            self::assertTrue($result, "User user-{$i} should be included at 100%");
        }
    }

    #[Test]
    public function evaluateUsesDefaultContextWhenNull(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'simple',
            enabled: true,
            type: FlagType::Boolean,
        ));

        $eval = $manager->evaluate('simple');

        self::assertTrue($eval->result);
        self::assertInstanceOf(FlagContext::class, $eval->context);
        self::assertNull($eval->context->userId);
        self::assertNull($eval->context->tenantId);
        self::assertNull($eval->context->environment);
    }

    #[Test]
    public function disabledFlagAlwaysRecordsInLog(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'disabled',
            enabled: false,
            type: FlagType::Boolean,
        ));

        $manager->evaluate('disabled');
        self::assertSame(1, $this->log->count());

        $evals = $this->log->forFlag('disabled');
        self::assertFalse($evals[0]->result);
        self::assertSame(FlagEvaluationReason::FlagDisabled, $evals[0]->reason);
    }

    #[Test]
    public function contextualFlagWithEmptyAllowedTenantsSkipsTenantCheck(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'users-only',
            enabled: true,
            type: FlagType::Contextual,
            allowedTenants: [],
            allowedUsers: ['user-1'],
        ));

        $context = new FlagContext(tenantId: 'any-tenant', userId: 'user-1');
        $eval = $manager->evaluate('users-only', $context);

        self::assertTrue($eval->result);
        self::assertSame(FlagEvaluationReason::UserMatch, $eval->reason);
    }

    #[Test]
    public function contextualFlagWithEmptyAllowedUsersSkipsUserCheck(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'env-only',
            enabled: true,
            type: FlagType::Contextual,
            allowedUsers: [],
            allowedEnvironments: ['staging'],
        ));

        $context = new FlagContext(userId: 'any-user', environment: 'staging');
        $eval = $manager->evaluate('env-only', $context);

        self::assertTrue($eval->result);
        self::assertSame(FlagEvaluationReason::EnvironmentMatch, $eval->reason);
    }

    #[Test]
    public function contextualFlagWithNullTenantIdSkipsTenantCheck(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'tenant-check',
            enabled: true,
            type: FlagType::Contextual,
            allowedTenants: ['acme'],
            allowedUsers: ['user-1'],
        ));

        // No tenantId in context, should fall through to user check
        $context = new FlagContext(userId: 'user-1');
        $eval = $manager->evaluate('tenant-check', $context);

        self::assertTrue($eval->result);
        self::assertSame(FlagEvaluationReason::UserMatch, $eval->reason);
    }

    #[Test]
    public function contextualFlagWithNullUserIdSkipsUserCheck(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'user-check',
            enabled: true,
            type: FlagType::Contextual,
            allowedUsers: ['user-1'],
            allowedEnvironments: ['prod'],
        ));

        // No userId in context, should fall through to environment check
        $context = new FlagContext(environment: 'prod');
        $eval = $manager->evaluate('user-check', $context);

        self::assertTrue($eval->result);
        self::assertSame(FlagEvaluationReason::EnvironmentMatch, $eval->reason);
    }

    #[Test]
    public function contextualFlagWithNullEnvironmentSkipsEnvCheck(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'env-check',
            enabled: true,
            type: FlagType::Contextual,
            allowedEnvironments: ['production'],
        ));

        // No environment in context
        $context = new FlagContext();
        $eval = $manager->evaluate('env-check', $context);

        self::assertFalse($eval->result);
        self::assertSame(FlagEvaluationReason::DefaultState, $eval->reason);
    }

    #[Test]
    public function evaluationRecordsTimestamp(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'timestamped',
            enabled: true,
            type: FlagType::Boolean,
        ));

        $eval = $manager->evaluate('timestamped');

        self::assertInstanceOf(DateTimeImmutable::class, $eval->evaluatedAt);
        self::assertSame('timestamped', $eval->flagName);
    }

    #[Test]
    public function evaluationRecordsContext(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'ctx-flag',
            enabled: true,
            type: FlagType::Boolean,
        ));

        $context = new FlagContext(userId: 'u1', tenantId: 't1', environment: 'test');
        $eval = $manager->evaluate('ctx-flag', $context);

        self::assertSame('u1', $eval->context->userId);
        self::assertSame('t1', $eval->context->tenantId);
        self::assertSame('test', $eval->context->environment);
    }

    #[Test]
    #[DataProvider('percentageRolloutProvider')]
    public function percentageRolloutIsDeterministic(string $userId, int $percentage): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        $this->storage->set(new FlagDefinition(
            name: 'det-flag',
            enabled: true,
            type: FlagType::Percentage,
            percentage: $percentage,
        ));

        $context = new FlagContext(userId: $userId);

        // Hash-based bucket calculation
        $hash = crc32('det-flag' . $userId);
        $bucket = (($hash % 100) + 100) % 100;
        $expected = $bucket < $percentage;

        $result = $manager->isEnabled('det-flag', $context);
        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function percentageRolloutProvider(): iterable
    {
        yield 'user-a at 25%' => ['user-a', 25];
        yield 'user-b at 50%' => ['user-b', 50];
        yield 'user-c at 75%' => ['user-c', 75];
        yield 'user-d at 10%' => ['user-d', 10];
        yield 'user-e at 90%' => ['user-e', 90];
    }

    #[Test]
    public function allFlagsReturnsEmptyOnEmptyStorage(): void
    {
        $manager = new FeatureFlagManager($this->storage, $this->log);

        self::assertSame([], $manager->allFlags());
    }

    #[Test]
    public function flagContextConstructorWithAttributes(): void
    {
        $context = new FlagContext(
            tenantId: 't1',
            userId: 'u1',
            environment: 'prod',
            attributes: ['role' => 'admin', 'plan' => 'enterprise'],
        );

        self::assertSame('t1', $context->tenantId);
        self::assertSame('u1', $context->userId);
        self::assertSame('prod', $context->environment);
        self::assertSame(['role' => 'admin', 'plan' => 'enterprise'], $context->attributes);
    }

    #[Test]
    public function flagContextDefaultsToNulls(): void
    {
        $context = new FlagContext();

        self::assertNull($context->tenantId);
        self::assertNull($context->userId);
        self::assertNull($context->environment);
        self::assertSame([], $context->attributes);
    }
}
