<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Pagination;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Pagination\PageLink;

final class PageLinkTest extends TestCase
{
    #[Test]
    public function ellipsisFactoryCreatesCorrectLink(): void
    {
        $link = PageLink::ellipsis();

        self::assertSame(0, $link->page);
        self::assertSame('...', $link->label);
        self::assertFalse($link->isActive);
        self::assertTrue($link->isDisabled);
        self::assertTrue($link->isEllipsis);
    }

    #[Test]
    public function regularLinkIsNotEllipsis(): void
    {
        $link = new PageLink(page: 3, label: '3', isActive: false, isDisabled: false);

        self::assertSame(3, $link->page);
        self::assertFalse($link->isEllipsis);
        self::assertFalse($link->isActive);
    }

    #[Test]
    public function activeLinkMarkedCorrectly(): void
    {
        $link = new PageLink(page: 5, label: '5', isActive: true, isDisabled: false);

        self::assertTrue($link->isActive);
        self::assertFalse($link->isDisabled);
    }

    #[Test]
    public function disabledLinkMarkedCorrectly(): void
    {
        $link = new PageLink(page: 1, label: '&laquo;', isActive: false, isDisabled: true);

        self::assertTrue($link->isDisabled);
        self::assertFalse($link->isActive);
    }
}
