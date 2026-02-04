<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\LinkAction;
use Pulsar\Extension\SocialSso\Domain\LinkedIdentityResult;

#[CoversClass(LinkedIdentityResult::class)]
final class LinkedIdentityResultTest extends TestCase
{
    #[Test]
    public function linkedResultHasIdentityId(): void
    {
        $result = new LinkedIdentityResult(
            linked: true,
            identityId: 'user-42',
            action: LinkAction::Linked,
        );

        self::assertTrue($result->linked);
        self::assertSame('user-42', $result->identityId);
        self::assertSame(LinkAction::Linked, $result->action);
    }

    #[Test]
    public function unlinkedResultHasNullIdentityId(): void
    {
        $result = new LinkedIdentityResult(
            linked: false,
            identityId: null,
            action: LinkAction::Unlinked,
        );

        self::assertFalse($result->linked);
        self::assertNull($result->identityId);
        self::assertSame(LinkAction::Unlinked, $result->action);
    }
}
