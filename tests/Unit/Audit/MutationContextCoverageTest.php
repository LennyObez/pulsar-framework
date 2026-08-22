<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;

#[CoversClass(MutationContext::class)]
final class MutationContextCoverageTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $context = new MutationContext(
            actor: 'admin@example.com',
            reason: 'User requested password reset',
            correlationId: 'corr-123',
        );

        self::assertSame('admin@example.com', $context->actor);
        self::assertSame('User requested password reset', $context->reason);
        self::assertSame('corr-123', $context->correlationId);
    }

    #[Test]
    public function constructorDefaultsCorrelationIdToNull(): void
    {
        $context = new MutationContext(
            actor: 'user@test.com',
            reason: 'Data migration',
        );

        self::assertNull($context->correlationId);
    }

    #[Test]
    public function systemFactorySetsActorToSystem(): void
    {
        $context = MutationContext::system('Scheduled cleanup');

        self::assertSame('system', $context->actor);
        self::assertSame('Scheduled cleanup', $context->reason);
        self::assertNull($context->correlationId);
    }

    #[Test]
    public function systemFactoryWithCorrelationId(): void
    {
        $context = MutationContext::system('Cache invalidation', 'req-456');

        self::assertSame('system', $context->actor);
        self::assertSame('Cache invalidation', $context->reason);
        self::assertSame('req-456', $context->correlationId);
    }

    #[Test]
    public function systemFactoryReturnsImmutableInstance(): void
    {
        $ctx1 = MutationContext::system('reason1', 'id1');
        $ctx2 = MutationContext::system('reason2', 'id2');

        self::assertNotSame($ctx1, $ctx2);
        self::assertSame('reason1', $ctx1->reason);
        self::assertSame('reason2', $ctx2->reason);
    }
}
