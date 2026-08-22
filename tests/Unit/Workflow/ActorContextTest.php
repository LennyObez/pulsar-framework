<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\ActorContext;

#[CoversClass(ActorContext::class)]
final class ActorContextTest extends TestCase
{
    #[Test]
    public function test_constructor_sets_subject_id(): void
    {
        $actor = new ActorContext(subjectId: 'user-42');

        self::assertSame('user-42', $actor->subjectId);
        self::assertNull($actor->tenantId);
        self::assertNull($actor->claimsSnapshotId);
    }

    #[Test]
    public function test_constructor_sets_all_fields(): void
    {
        $actor = new ActorContext(
            subjectId: 'user-42',
            tenantId: 'tenant-1',
            claimsSnapshotId: 'snap-abc',
        );

        self::assertSame('user-42', $actor->subjectId);
        self::assertSame('tenant-1', $actor->tenantId);
        self::assertSame('snap-abc', $actor->claimsSnapshotId);
    }

    #[Test]
    public function test_tenant_id_defaults_to_null(): void
    {
        $actor = new ActorContext(subjectId: 'user-1');

        self::assertNull($actor->tenantId);
    }

    #[Test]
    public function test_claims_snapshot_id_defaults_to_null(): void
    {
        $actor = new ActorContext(subjectId: 'user-1');

        self::assertNull($actor->claimsSnapshotId);
    }

    #[Test]
    public function test_empty_subject_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('subjectId must not be empty');

        new ActorContext(subjectId: '');
    }
}
