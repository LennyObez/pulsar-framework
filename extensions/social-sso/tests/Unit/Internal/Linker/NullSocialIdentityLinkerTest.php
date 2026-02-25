<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Internal\Linker;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\LinkAction;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Internal\Linker\NullSocialIdentityLinker;

final class NullSocialIdentityLinkerTest extends TestCase
{
    #[Test]
    public function linkAlwaysReturnsUnlinked(): void
    {
        $linker = new NullSocialIdentityLinker();
        $identity = new SocialIdentity('github', 'u-1');

        $result = $linker->link($identity);

        self::assertFalse($result->linked);
        self::assertNull($result->identityId);
        self::assertSame(LinkAction::Unlinked, $result->action);
    }
}
