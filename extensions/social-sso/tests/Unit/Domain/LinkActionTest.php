<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\LinkAction;

final class LinkActionTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('linked', LinkAction::Linked->value);
        self::assertSame('created', LinkAction::Created->value);
        self::assertSame('unlinked', LinkAction::Unlinked->value);
        self::assertSame('rejected', LinkAction::Rejected->value);
    }

    #[Test]
    public function fromReturnsCorrectCase(): void
    {
        self::assertSame(LinkAction::Linked, LinkAction::from('linked'));
        self::assertSame(LinkAction::Rejected, LinkAction::from('rejected'));
    }
}
