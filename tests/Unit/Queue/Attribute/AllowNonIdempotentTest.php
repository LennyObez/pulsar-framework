<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Attribute\AllowNonIdempotent;

#[CoversClass(AllowNonIdempotent::class)]
final class AllowNonIdempotentTest extends TestCase
{
    #[Test]
    public function holdsReasonAndReviewer(): void
    {
        $attr = new AllowNonIdempotent(
            reason: 'Payment webhook cannot be replayed safely',
            reviewer: 'jane.doe@example.com',
        );

        self::assertSame('Payment webhook cannot be replayed safely', $attr->reason);
        self::assertSame('jane.doe@example.com', $attr->reviewer);
    }
}
