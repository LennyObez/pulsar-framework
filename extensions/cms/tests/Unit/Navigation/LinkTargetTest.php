<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Navigation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Navigation\LinkTarget;

#[CoversNothing]
final class LinkTargetTest extends TestCase
{
    #[Test]
    public function selfHasCorrectValue(): void
    {
        self::assertSame('_self', LinkTarget::Self->value);
    }

    #[Test]
    public function blankHasCorrectValue(): void
    {
        self::assertSame('_blank', LinkTarget::Blank->value);
    }

    #[Test]
    public function fromValueResolvesSelf(): void
    {
        self::assertSame(LinkTarget::Self, LinkTarget::from('_self'));
    }

    #[Test]
    public function fromValueResolvesBlank(): void
    {
        self::assertSame(LinkTarget::Blank, LinkTarget::from('_blank'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(LinkTarget::tryFrom('_parent'));
    }
}
