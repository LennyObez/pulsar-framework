<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Collector;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Collector\FeatureFlagCollector;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\FeatureFlagPayload;
use Pulsar\Extension\Studio\FiberScopedContextProvider;
use Pulsar\FeatureFlag\FlagContext;
use Pulsar\FeatureFlag\FlagEvaluation;
use Pulsar\FeatureFlag\FlagEvaluationReason;
use Pulsar\Observability\Context\CorrelationContext;
use RuntimeException;

#[CoversClass(FeatureFlagCollector::class)]
final class FeatureFlagCollectorTest extends TestCase
{
    /** @var list<array{event: ConsoleEvent, context: ?CorrelationContext}> */
    private array $emittedEvents = [];

    /** @psalm-suppress PropertyNotSetInConstructor */
    private FiberScopedContextProvider $contextProvider;

    protected function setUp(): void
    {
        $this->emittedEvents = [];
        $this->contextProvider = new FiberScopedContextProvider();
    }

    #[Test]
    public function handleEvaluationEmitsFeatureFlagEvent(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'new-checkout-flow',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(FeatureFlagPayload::class, $this->emittedEvents[0]['event']);
    }

    #[Test]
    public function handleEvaluationRecordsFlagName(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'dark-mode',
            result: true,
            reason: FlagEvaluationReason::DefaultState,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        /** @var FeatureFlagPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('dark-mode', $payload->flagName);
    }

    #[Test]
    public function handleEvaluationRecordsTrueResult(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'feature-x',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        /** @var FeatureFlagPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertTrue($payload->result);
    }

    #[Test]
    public function handleEvaluationRecordsFalseResult(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'feature-y',
            result: false,
            reason: FlagEvaluationReason::FlagDisabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        /** @var FeatureFlagPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertFalse($payload->result);
    }

    #[Test]
    public function handleEvaluationRecordsReason(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'premium-feature',
            result: true,
            reason: FlagEvaluationReason::TenantMatch,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        /** @var FeatureFlagPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('tenant_match', $payload->reason);
    }

    #[Test]
    public function handleEvaluationRecordsUserIdAsContextIdentifier(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'user-feature',
            result: true,
            reason: FlagEvaluationReason::UserMatch,
            context: new FlagContext(userId: 'user-123', tenantId: 'tenant-456'),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        /** @var FeatureFlagPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        // userId takes precedence per the implementation's null coalesce
        self::assertSame('user-123', $payload->contextIdentifier);
    }

    #[Test]
    public function handleEvaluationRecordsTenantIdWhenNoUserId(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'tenant-feature',
            result: true,
            reason: FlagEvaluationReason::TenantMatch,
            context: new FlagContext(tenantId: 'tenant-789'),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        /** @var FeatureFlagPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('tenant-789', $payload->contextIdentifier);
    }

    #[Test]
    public function handleEvaluationRecordsNullContextIdentifierWhenNoUserOrTenant(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'global-feature',
            result: true,
            reason: FlagEvaluationReason::DefaultState,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        /** @var FeatureFlagPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertNull($payload->contextIdentifier);
    }

    #[Test]
    public function handleEvaluationIncludesCorrelationContext(): void
    {
        $collector = $this->createCollector();

        $context = new CorrelationContext(
            requestId: 'req-abc',
            traceId: 'trace-xyz',
            jobId: 'job-123',
        );
        $scope = $this->contextProvider->enter($context);

        try {
            $evaluation = new FlagEvaluation(
                flagName: 'test-flag',
                result: true,
                reason: FlagEvaluationReason::FlagEnabled,
                context: new FlagContext(),
                evaluatedAt: new DateTimeImmutable(),
            );

            $collector->handleEvaluation($evaluation);

            $emittedContext = $this->emittedEvents[0]['context'];
            self::assertNotNull($emittedContext);
            self::assertSame('req-abc', $emittedContext->requestId);
            self::assertSame('trace-xyz', $emittedContext->traceId);
            self::assertSame('job-123', $emittedContext->jobId);
        } finally {
            $scope->close();
        }
    }

    #[Test]
    public function handleEvaluationSkipsEmissionWhenDisabled(): void
    {
        $collector = $this->createCollector();
        $collector->enabled = false;

        $evaluation = new FlagEvaluation(
            flagName: 'any-flag',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function isEnabledReturnsTrueByDefault(): void
    {
        $collector = $this->createCollector();

        self::assertTrue($collector->enabled);
    }

    #[Test]
    public function setEnabledChangesEnabledState(): void
    {
        $collector = $this->createCollector();

        $collector->enabled = false;
        self::assertFalse($collector->enabled);

        $collector->enabled = true;
        self::assertTrue($collector->enabled);
    }

    #[Test]
    public function handleEvaluationSilentlySwallowsEmitExceptions(): void
    {
        $collector = new FeatureFlagCollector(
            contextProvider: $this->contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                throw new RuntimeException('Emit failed');
            },
        );

        $evaluation = new FlagEvaluation(
            flagName: 'test-flag',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        // Should not throw
        $collector->handleEvaluation($evaluation);

        // Test passes if no exception was thrown - use expectNotToPerformAssertions
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function handleEvaluationRecordsAllReasonTypes(): void
    {
        $reasons = [
            [FlagEvaluationReason::FlagDisabled, 'flag_disabled'],
            [FlagEvaluationReason::FlagEnabled, 'flag_enabled'],
            [FlagEvaluationReason::FlagNotFound, 'flag_not_found'],
            [FlagEvaluationReason::DefaultState, 'default_state'],
            [FlagEvaluationReason::TenantMatch, 'tenant_match'],
            [FlagEvaluationReason::UserMatch, 'user_match'],
            [FlagEvaluationReason::EnvironmentMatch, 'environment_match'],
            [FlagEvaluationReason::PercentageRollout, 'percentage_rollout'],
            [FlagEvaluationReason::PercentageExcluded, 'percentage_excluded'],
        ];

        foreach ($reasons as [$reason, $expectedValue]) {
            // Create a fresh collector for each reason to have a clean events array
            $events = [];
            $collector = new FeatureFlagCollector(
                contextProvider: $this->contextProvider,
                emit: function (ConsoleEvent $event, ?CorrelationContext $context) use (&$events): void {
                    $events[] = ['event' => $event, 'context' => $context];
                },
            );

            $evaluation = new FlagEvaluation(
                flagName: 'test-flag',
                result: true,
                reason: $reason,
                context: new FlagContext(),
                evaluatedAt: new DateTimeImmutable(),
            );

            $collector->handleEvaluation($evaluation);

            self::assertCount(1, $events);
            /** @var FeatureFlagPayload $payload */
            $payload = $events[0]['event'];
            self::assertSame($expectedValue, $payload->reason, "Failed for reason: {$reason->value}");
        }
    }

    #[Test]
    public function handleEvaluationHandlesMultipleSequentialEvaluations(): void
    {
        $collector = $this->createCollector();

        $flags = ['flag-a', 'flag-b', 'flag-c'];

        foreach ($flags as $flagName) {
            $evaluation = new FlagEvaluation(
                flagName: $flagName,
                result: true,
                reason: FlagEvaluationReason::FlagEnabled,
                context: new FlagContext(),
                evaluatedAt: new DateTimeImmutable(),
            );

            $collector->handleEvaluation($evaluation);
        }

        self::assertCount(3, $this->emittedEvents);

        /** @var FeatureFlagPayload $payload0 */
        $payload0 = $this->emittedEvents[0]['event'];
        /** @var FeatureFlagPayload $payload1 */
        $payload1 = $this->emittedEvents[1]['event'];
        /** @var FeatureFlagPayload $payload2 */
        $payload2 = $this->emittedEvents[2]['event'];

        self::assertSame('flag-a', $payload0->flagName);
        self::assertSame('flag-b', $payload1->flagName);
        self::assertSame('flag-c', $payload2->flagName);
    }

    #[Test]
    public function handleEvaluationWithEnvironmentContext(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'env-specific-feature',
            result: true,
            reason: FlagEvaluationReason::EnvironmentMatch,
            context: new FlagContext(environment: 'production'),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        /** @var FeatureFlagPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('environment_match', $payload->reason);
        // Note: environment is not captured in contextIdentifier, which uses userId ?? tenantId
        self::assertNull($payload->contextIdentifier);
    }

    #[Test]
    public function handleEvaluationWithAttributesInContext(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'attribute-based-feature',
            result: true,
            reason: FlagEvaluationReason::PercentageRollout,
            context: new FlagContext(
                userId: 'user-with-attrs',
                attributes: ['plan' => 'premium', 'country' => 'US'],
            ),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        /** @var FeatureFlagPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('user-with-attrs', $payload->contextIdentifier);
        // Attributes are not stored in the payload per design (only flag-level data)
    }

    #[Test]
    public function handleEvaluationReturnsVoid(): void
    {
        $collector = $this->createCollector();

        $evaluation = new FlagEvaluation(
            flagName: 'test-flag',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        );

        // Verify method returns void (no return value)
        $collector->handleEvaluation($evaluation);

        // If we got here, the method returned successfully
        self::assertCount(1, $this->emittedEvents);
    }

    private function createCollector(): FeatureFlagCollector
    {
        return new FeatureFlagCollector(
            contextProvider: $this->contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );
    }
}
