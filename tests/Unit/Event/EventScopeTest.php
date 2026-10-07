<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\EventScope;

#[CoversNothing]
final class EventScopeTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('internal', EventScope::Internal->value);
        self::assertSame('cross_module', EventScope::CrossModule->value);
    }

    #[Test]
    public function fromValidString(): void
    {
        self::assertSame(EventScope::Internal, EventScope::from('internal'));
        self::assertSame(EventScope::CrossModule, EventScope::from('cross_module'));
    }
}
