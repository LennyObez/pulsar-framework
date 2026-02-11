<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Pulsar\Queue\Attribute\AllowNonIdempotent;
use Pulsar\Queue\Attribute\EffectClassifier;
use Pulsar\Queue\Attribute\Idempotent;
use Pulsar\Queue\Attribute\NonIdempotent;
use Pulsar\Queue\Attribute\SystemJob;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Event\NonIdempotentJobAllowed;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\Middleware\EnforceEffectClassification;

#[Idempotent]
final class IdempotentJobForEnforce {}

#[NonIdempotent(reason: 'Sends payment')]
final class NonIdempotentJobForEnforce {}

#[NonIdempotent(reason: 'Sends payment')]
#[AllowNonIdempotent(reason: 'Compliance approved', reviewer: 'auditor@example.com')]
final class AllowedNonIdempotentJobForEnforce {}

#[SystemJob]
#[Idempotent]
final class SystemJobForEnforce {}

#[CoversClass(EnforceEffectClassification::class)]
final class EnforceEffectClassificationTest extends TestCase
{
    private EffectClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new EffectClassifier();
    }

    private function createEnvelope(string $jobClass, ?string $subjectId = 'user-42'): JobEnvelope
    {
        return new JobEnvelope(
            id: 'job-enforce-001',
            jobClass: $jobClass,
            payload: '{}',
            queue: 'regulated',
            idempotencyKey: 'idem-001',
            correlationId: 'corr-001',
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Fixed,
            retryDelayMs: 1000,
            tenantId: 'tenant-1',
            subjectId: $subjectId,
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1709827200,
            encrypted: false,
        );
    }

    #[Test]
    public function allowsIdempotentJobWithSubjectId(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $middleware = new EnforceEffectClassification($this->classifier, $dispatcher);

        $passed = false;
        $middleware->handle(
            $this->createEnvelope(IdempotentJobForEnforce::class),
            static function () use (&$passed): string {
                $passed = true;

                return 'ok';
            },
        );

        self::assertTrue($passed);
    }

    #[Test]
    public function throwsWhenNonIdempotentNotAllowed(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $middleware = new EnforceEffectClassification($this->classifier, $dispatcher);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageMatches('/NonIdempotent/');

        $middleware->handle(
            $this->createEnvelope(NonIdempotentJobForEnforce::class),
            static fn(): string => 'ok',
        );
    }

    #[Test]
    public function allowsNonIdempotentWithExplicitAllowanceAndDispatchesEvent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(NonIdempotentJobAllowed::class))
            ->willReturnCallback(static fn(object $e): object => $e);

        $middleware = new EnforceEffectClassification($this->classifier, $dispatcher);

        $middleware->handle(
            $this->createEnvelope(AllowedNonIdempotentJobForEnforce::class),
            static fn(): string => 'ok',
        );
    }

    #[Test]
    public function throwsWhenSubjectIdMissingOnNonSystemJob(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $middleware = new EnforceEffectClassification($this->classifier, $dispatcher);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageMatches('/subject ID/');

        $middleware->handle(
            $this->createEnvelope(IdempotentJobForEnforce::class, subjectId: null),
            static fn(): string => 'ok',
        );
    }

    #[Test]
    public function allowsSystemJobWithoutSubjectId(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $middleware = new EnforceEffectClassification($this->classifier, $dispatcher);

        $passed = false;
        $middleware->handle(
            $this->createEnvelope(SystemJobForEnforce::class, subjectId: null),
            static function () use (&$passed): string {
                $passed = true;

                return 'ok';
            },
        );

        self::assertTrue($passed);
    }
}
