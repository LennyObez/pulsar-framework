<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use ReflectionClass;

#[CoversClass(MutationContext::class)]
final class MutationContextTest extends TestCase
{
    #[Test]
    public function constructorSetsActor(): void
    {
        $ctx = new MutationContext(actor: 'admin', reason: 'bulk update');

        self::assertSame('admin', $ctx->actor);
    }

    #[Test]
    public function constructorSetsReason(): void
    {
        $ctx = new MutationContext(actor: 'admin', reason: 'compliance purge');

        self::assertSame('compliance purge', $ctx->reason);
    }

    #[Test]
    public function constructorSetsCorrelationId(): void
    {
        $ctx = new MutationContext(actor: 'admin', reason: 'update', correlationId: 'corr-123');

        self::assertSame('corr-123', $ctx->correlationId);
    }

    #[Test]
    public function correlationIdDefaultsToNull(): void
    {
        $ctx = new MutationContext(actor: 'user', reason: 'edit');

        self::assertNull($ctx->correlationId);
    }

    #[Test]
    public function systemFactorySetsActorToSystem(): void
    {
        $ctx = MutationContext::system('scheduled cleanup');

        self::assertSame('system', $ctx->actor);
    }

    #[Test]
    public function systemFactorySetsReason(): void
    {
        $ctx = MutationContext::system('data migration');

        self::assertSame('data migration', $ctx->reason);
    }

    #[Test]
    public function systemFactoryWithCorrelationId(): void
    {
        $ctx = MutationContext::system('cleanup', 'corr-abc');

        self::assertSame('system', $ctx->actor);
        self::assertSame('cleanup', $ctx->reason);
        self::assertSame('corr-abc', $ctx->correlationId);
    }

    #[Test]
    public function systemFactoryDefaultsCorrelationIdToNull(): void
    {
        $ctx = MutationContext::system('batch operation');

        self::assertNull($ctx->correlationId);
    }

    #[Test]
    public function isReadonly(): void
    {
        $ref = new ReflectionClass(MutationContext::class);

        self::assertTrue($ref->isReadOnly());
    }

    #[Test]
    public function isFinal(): void
    {
        $ref = new ReflectionClass(MutationContext::class);

        self::assertTrue($ref->isFinal());
    }

    #[Test]
    public function actorPropertyIsPublic(): void
    {
        $ref = new ReflectionClass(MutationContext::class);
        $prop = $ref->getProperty('actor');

        self::assertTrue($prop->isPublic());
    }

    #[Test]
    public function reasonPropertyIsPublic(): void
    {
        $ref = new ReflectionClass(MutationContext::class);
        $prop = $ref->getProperty('reason');

        self::assertTrue($prop->isPublic());
    }

    #[Test]
    public function correlationIdPropertyIsPublic(): void
    {
        $ref = new ReflectionClass(MutationContext::class);
        $prop = $ref->getProperty('correlationId');

        self::assertTrue($prop->isPublic());
    }

    #[Test]
    public function actorCanBeEmptyString(): void
    {
        $ctx = new MutationContext(actor: '', reason: 'anonymous');

        self::assertSame('', $ctx->actor);
    }

    #[Test]
    public function reasonCanBeEmptyString(): void
    {
        $ctx = new MutationContext(actor: 'admin', reason: '');

        self::assertSame('', $ctx->reason);
    }

    #[Test]
    public function hasApiAttribute(): void
    {
        $ref = new ReflectionClass(MutationContext::class);
        $attrs = $ref->getAttributes(\Pulsar\Api\Api::class);

        self::assertCount(1, $attrs);
    }
}
