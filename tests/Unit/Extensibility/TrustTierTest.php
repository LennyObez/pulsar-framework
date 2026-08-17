<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\TrustTier;

#[CoversNothing]
final class TrustTierTest extends TestCase
{
    #[Test]
    public function enumHasFourCases(): void
    {
        $cases = TrustTier::cases();

        self::assertCount(4, $cases);
    }

    #[Test]
    public function backedStringValues(): void
    {
        self::assertSame('core', TrustTier::Core->value);
        self::assertSame('verified', TrustTier::Verified->value);
        self::assertSame('community', TrustTier::Community->value);
        self::assertSame('untrusted', TrustTier::Untrusted->value);
    }

    #[Test]
    public function tryFromReturnsEnumForValidValues(): void
    {
        self::assertSame(TrustTier::Core, TrustTier::tryFrom('core'));
        self::assertSame(TrustTier::Verified, TrustTier::tryFrom('verified'));
        self::assertSame(TrustTier::Community, TrustTier::tryFrom('community'));
        self::assertSame(TrustTier::Untrusted, TrustTier::tryFrom('untrusted'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(TrustTier::tryFrom('invalid'));
        self::assertNull(TrustTier::tryFrom(''));
    }

    #[Test]
    public function coreIsAtLeastAllTiers(): void
    {
        self::assertTrue(TrustTier::Core->atLeast(TrustTier::Core));
        self::assertTrue(TrustTier::Core->atLeast(TrustTier::Verified));
        self::assertTrue(TrustTier::Core->atLeast(TrustTier::Community));
        self::assertTrue(TrustTier::Core->atLeast(TrustTier::Untrusted));
    }

    #[Test]
    public function verifiedIsAtLeastVerifiedAndBelow(): void
    {
        self::assertFalse(TrustTier::Verified->atLeast(TrustTier::Core));
        self::assertTrue(TrustTier::Verified->atLeast(TrustTier::Verified));
        self::assertTrue(TrustTier::Verified->atLeast(TrustTier::Community));
        self::assertTrue(TrustTier::Verified->atLeast(TrustTier::Untrusted));
    }

    #[Test]
    public function communityIsAtLeastCommunityAndBelow(): void
    {
        self::assertFalse(TrustTier::Community->atLeast(TrustTier::Core));
        self::assertFalse(TrustTier::Community->atLeast(TrustTier::Verified));
        self::assertTrue(TrustTier::Community->atLeast(TrustTier::Community));
        self::assertTrue(TrustTier::Community->atLeast(TrustTier::Untrusted));
    }

    #[Test]
    public function untrustedIsAtLeastOnlyUntrusted(): void
    {
        self::assertFalse(TrustTier::Untrusted->atLeast(TrustTier::Core));
        self::assertFalse(TrustTier::Untrusted->atLeast(TrustTier::Verified));
        self::assertFalse(TrustTier::Untrusted->atLeast(TrustTier::Community));
        self::assertTrue(TrustTier::Untrusted->atLeast(TrustTier::Untrusted));
    }
}
