<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\LinkAction;
use Pulsar\Extension\SocialSso\Domain\LinkedIdentityResult;

final class LinkedIdentityResultTest extends TestCase
{
    #[Test]
    public function constructsLinkedResult(): void
    {
        $result = new LinkedIdentityResult(
            linked: true,
            identityId: 'id-456',
            action: LinkAction::Linked,
        );

        self::assertTrue($result->linked);
        self::assertSame('id-456', $result->identityId);
        self::assertSame(LinkAction::Linked, $result->action);
    }

    #[Test]
    public function constructsRejectedResult(): void
    {
        $result = new LinkedIdentityResult(
            linked: false,
            identityId: null,
            action: LinkAction::Rejected,
        );

        self::assertFalse($result->linked);
        self::assertNull($result->identityId);
        self::assertSame(LinkAction::Rejected, $result->action);
    }
}
