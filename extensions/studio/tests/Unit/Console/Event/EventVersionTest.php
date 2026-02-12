<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

final class EventVersionTest extends TestCase
{
    #[Test]
    public function v1HasValue1(): void
    {
        self::assertSame(1, EventVersion::V1->value);
    }

    #[Test]
    public function canBeCreatedFromInteger(): void
    {
        $version = EventVersion::from(1);

        self::assertSame(EventVersion::V1, $version);
    }
}
