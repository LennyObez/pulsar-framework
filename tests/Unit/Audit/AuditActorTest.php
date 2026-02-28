<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Audit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditActorKind;

#[CoversClass(AuditActor::class)]
#[CoversClass(AuditActorKind::class)]
final class AuditActorTest extends TestCase
{
    #[Test]
    public function userFactoryProducesUserKind(): void
    {
        $actor = AuditActor::user('alice');

        self::assertSame(AuditActorKind::User, $actor->kind);
        self::assertSame('alice', $actor->id);
        self::assertSame('alice', (string) $actor);
    }

    #[Test]
    public function serviceAccountFactoryProducesServiceAccountKind(): void
    {
        $actor = AuditActor::serviceAccount('payments-worker');

        self::assertSame(AuditActorKind::ServiceAccount, $actor->kind);
        self::assertSame('payments-worker', $actor->id);
    }

    #[Test]
    public function systemFactoryNamespacesComponentInId(): void
    {
        $actor = AuditActor::system('mail.webhook');

        self::assertSame(AuditActorKind::System, $actor->kind);
        self::assertSame('system:mail.webhook', $actor->id);
    }

    #[Test]
    public function systemFactoryDefaultsToUnknownComponent(): void
    {
        $actor = AuditActor::system();

        self::assertSame('system:unknown', $actor->id);
    }

    #[Test]
    public function anonymousFactoryProducesAnonymousKind(): void
    {
        $actor = AuditActor::anonymous();

        self::assertSame(AuditActorKind::Anonymous, $actor->kind);
        self::assertSame('anonymous', $actor->id);
    }

    #[Test]
    public function constructorRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty');

        new AuditActor(AuditActorKind::User, '');
    }

    #[Test]
    public function constructorRejectsWhitespaceOnlyId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditActor(AuditActorKind::User, "  \t\n");
    }

    #[Test]
    public function userFactoryRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (void) AuditActor::user('');
    }
}
