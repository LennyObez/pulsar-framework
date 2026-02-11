<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Attribute\NonIdempotent;

#[CoversClass(NonIdempotent::class)]
final class NonIdempotentTest extends TestCase
{
    #[Test]
    public function holdsReason(): void
    {
        $attr = new NonIdempotent(reason: 'Sends transactional email');

        self::assertSame('Sends transactional email', $attr->reason);
    }
}
