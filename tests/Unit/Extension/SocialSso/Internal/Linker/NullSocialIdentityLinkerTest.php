<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Internal\Linker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\LinkAction;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Internal\Linker\NullSocialIdentityLinker;

#[CoversClass(NullSocialIdentityLinker::class)]
final class NullSocialIdentityLinkerTest extends TestCase
{
    #[Test]
    public function alwaysReturnsUnlinked(): void
    {
        $linker = new NullSocialIdentityLinker();

        $identity = new SocialIdentity(provider: 'google', providerUserId: '123');
        $result = $linker->link($identity);

        self::assertFalse($result->linked);
        self::assertNull($result->identityId);
        self::assertSame(LinkAction::Unlinked, $result->action);
    }
}
