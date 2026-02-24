<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Attribute\Encrypted;

#[CoversClass(Encrypted::class)]
final class EncryptedTest extends TestCase
{
    #[Test]
    public function canBeInstantiated(): void
    {
        $attr = new Encrypted();

        self::assertInstanceOf(Encrypted::class, $attr);
    }
}
