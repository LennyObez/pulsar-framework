<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Attribute\Idempotent;

#[CoversClass(Idempotent::class)]
final class IdempotentTest extends TestCase
{
    #[Test]
    public function defaultKeyIsEmpty(): void
    {
        $attr = new Idempotent();

        self::assertSame('', $attr->key);
    }

    #[Test]
    public function customKeyIsPreserved(): void
    {
        $attr = new Idempotent(key: 'user:{userId}:export');

        self::assertSame('user:{userId}:export', $attr->key);
    }
}
