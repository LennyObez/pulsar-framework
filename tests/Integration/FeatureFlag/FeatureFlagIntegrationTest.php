<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\FeatureFlag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\FeatureFlagManager;
use Pulsar\FeatureFlag\FlagContext;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagEvaluationReason;
use Pulsar\FeatureFlag\FlagType;
use Pulsar\FeatureFlag\Storage\FileFlagStorage;
use Pulsar\FeatureFlag\Storage\InMemoryFlagStorage;

#[CoversClass(FeatureFlagManager::class)]
#[CoversClass(FlagDefinition::class)]
#[CoversClass(FlagEvaluationLog::class)]
#[CoversClass(FlagContext::class)]
#[CoversClass(InMemoryFlagStorage::class)]
#[CoversClass(FileFlagStorage::class)]
final class FeatureFlagIntegrationTest extends TestCase
{
    private ?string $tempFile = null;

    protected function tearDown(): void
    {
        if ($this->tempFile !== null) {
            if (file_exists($this->tempFile)) {
                unlink($this->tempFile);
            }

            // tempnam() creates a base file without the .json suffix; clean it up
            $basePath = preg_replace('/\.json$/', '', $this->tempFile);

            if ($basePath !== null && $basePath !== $this->tempFile && file_exists($basePath)) {
                unlink($basePath);
            }

            $this->tempFile = null;
        }
    }

    #[Test]
    public function fullPipelineCreateStorageLoadFlagsAndEvaluate(): void
    {
        $storage = new InMemoryFlagStorage();
        $log = new FlagEvaluationLog();
        $manager = new FeatureFlagManager($storage, $log, defaultState: false);

        $storage->set(FlagDefinition::fromArray('dark-mode', [
            'enabled' => true,
            'type' => 'boolean',
        ]));

        $storage->set(FlagDefinition::fromArray('beta-feature', [
            'enabled' => false,
            'type' => 'boolean',
        ]));

        self::assertTrue($manager->isEnabled('dark-mode'));
        self::assertFalse($manager->isEnabled('beta-feature'));
        self::assertFalse($manager->isEnabled('nonexistent-flag'));

        self::assertSame(3, $log->count());

        $allFlags = $manager->allFlags();
        self::assertCount(2, $allFlags);
        self::assertArrayHasKey('dark-mode', $allFlags);
        self::assertArrayHasKey('beta-feature', $allFlags);
    }

    #[Test]
    public function booleanFlagEvaluationEndToEnd(): void
    {
        $storage = new InMemoryFlagStorage();
        $log = new FlagEvaluationLog();
        $manager = new FeatureFlagManager($storage, $log, defaultState: false);

        $storage->set(FlagDefinition::fromArray('feature-x', [
            'enabled' => true,
            'type' => 'boolean',
        ]));

        $evaluation = $manager->evaluate('feature-x');

        self::assertTrue($evaluation->result);
        self::assertSame('feature-x', $evaluation->flagName);
        self::assertSame(FlagEvaluationReason::FlagEnabled, $evaluation->reason);
    }

    #[Test]
    public function percentageFlagDeterminismAcrossMultipleEvaluations(): void
    {
        $storage = new InMemoryFlagStorage();
        $log = new FlagEvaluationLog();
        $manager = new FeatureFlagManager($storage, $log, defaultState: false);

        $storage->set(FlagDefinition::fromArray('gradual-rollout', [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 50,
        ]));

        $context = new FlagContext(userId: 'user-42');

        // Evaluate the same flag+context multiple times to verify determinism
        $firstResult = $manager->isEnabled('gradual-rollout', $context);

        for ($i = 0; $i < 10; $i++) {
            self::assertSame(
                $firstResult,
                $manager->isEnabled('gradual-rollout', $context),
                'Percentage flag must produce deterministic results for the same user context',
            );
        }

        // Verify different users may get different results (statistical, not guaranteed)
        $results = [];
        for ($i = 0; $i < 50; $i++) {
            $userContext = new FlagContext(userId: 'user-' . $i);
            $results[] = $manager->isEnabled('gradual-rollout', $userContext);
        }

        // With 50% rollout and 50 users, we expect some true and some false
        self::assertContains(true, $results, 'At least one user should have the flag enabled');
        self::assertContains(false, $results, 'At least one user should have the flag disabled');
    }

    #[Test]
    public function contextualFlagWithTenantContext(): void
    {
        $storage = new InMemoryFlagStorage();
        $log = new FlagEvaluationLog();
        $manager = new FeatureFlagManager($storage, $log, defaultState: false);

        $storage->set(new FlagDefinition(
            name: 'premium-dashboard',
            enabled: true,
            type: FlagType::Contextual,
            allowedTenants: ['acme', 'globex'],
        ));

        $acmeContext = new FlagContext(tenantId: 'acme', userId: 'user-1');
        $unknownContext = new FlagContext(tenantId: 'unknown-co', userId: 'user-2');
        $noTenantContext = new FlagContext(userId: 'user-3');

        self::assertTrue($manager->isEnabled('premium-dashboard', $acmeContext));
        self::assertFalse($manager->isEnabled('premium-dashboard', $unknownContext));
        self::assertFalse($manager->isEnabled('premium-dashboard', $noTenantContext));

        $evaluation = $manager->evaluate('premium-dashboard', $acmeContext);
        self::assertSame(FlagEvaluationReason::TenantMatch, $evaluation->reason);
    }

    #[Test]
    public function flagEvaluationLogRecordsAllEvaluations(): void
    {
        $storage = new InMemoryFlagStorage();
        $log = new FlagEvaluationLog();
        $manager = new FeatureFlagManager($storage, $log, defaultState: false);

        $storage->set(FlagDefinition::fromArray('feature-a', [
            'enabled' => true,
            'type' => 'boolean',
        ]));

        $storage->set(FlagDefinition::fromArray('feature-b', [
            'enabled' => false,
            'type' => 'boolean',
        ]));

        $manager->isEnabled('feature-a');
        $manager->isEnabled('feature-b');
        $manager->isEnabled('feature-a');
        $manager->isEnabled('nonexistent');

        self::assertSame(4, $log->count());

        $featureALogs = $log->forFlag('feature-a');
        self::assertCount(2, $featureALogs);
        self::assertTrue($featureALogs[0]->result);
        self::assertSame(FlagEvaluationReason::FlagEnabled, $featureALogs[0]->reason);

        $featureBLogs = $log->forFlag('feature-b');
        self::assertCount(1, $featureBLogs);
        self::assertFalse($featureBLogs[0]->result);
        self::assertSame(FlagEvaluationReason::FlagDisabled, $featureBLogs[0]->reason);

        $nonexistentLogs = $log->forFlag('nonexistent');
        self::assertCount(1, $nonexistentLogs);
        self::assertFalse($nonexistentLogs[0]->result);
        self::assertSame(FlagEvaluationReason::FlagNotFound, $nonexistentLogs[0]->reason);
    }

    #[Test]
    public function fileStoragePersistsAndLoadsFlags(): void
    {
        $tempBase = tempnam(sys_get_temp_dir(), 'pulsar_flags_');
        self::assertIsString($tempBase);
        $this->tempFile = $tempBase . '.json';

        // Write flags to file via FileFlagStorage
        $writeStorage = new FileFlagStorage($this->tempFile);
        $writeStorage->set(FlagDefinition::fromArray('persisted-flag', [
            'enabled' => true,
            'type' => 'boolean',
            'description' => 'A persisted flag',
        ]));
        $writeStorage->set(FlagDefinition::fromArray('another-flag', [
            'enabled' => true,
            'type' => 'percentage',
            'percentage' => 75,
        ]));

        // Create a fresh FileFlagStorage pointing to same file to verify persistence
        $readStorage = new FileFlagStorage($this->tempFile);

        self::assertTrue($readStorage->has('persisted-flag'));
        self::assertTrue($readStorage->has('another-flag'));

        $flag = $readStorage->get('persisted-flag');
        self::assertNotNull($flag);
        self::assertSame('persisted-flag', $flag->name);
        self::assertTrue($flag->enabled);
        self::assertSame(FlagType::Boolean, $flag->type);
        self::assertSame('A persisted flag', $flag->description);

        $percentFlag = $readStorage->get('another-flag');
        self::assertNotNull($percentFlag);
        self::assertSame(FlagType::Percentage, $percentFlag->type);
        self::assertSame(75, $percentFlag->percentage);

        // Use the file storage with the manager for an end-to-end round-trip
        $log = new FlagEvaluationLog();
        $manager = new FeatureFlagManager($readStorage, $log, defaultState: false);

        self::assertTrue($manager->isEnabled('persisted-flag'));
    }
}
