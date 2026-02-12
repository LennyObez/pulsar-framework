<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\BlindIndex;

final class BlindIndexTest extends TestCase
{
    #[Test]
    public function storesColumnAndDefaultHashLength(): void
    {
        $attr = new BlindIndex(column: 'email_idx');

        self::assertSame('email_idx', $attr->column);
        self::assertSame(32, $attr->hashLength);
    }

    #[Test]
    public function storesCustomHashLength(): void
    {
        $attr = new BlindIndex(column: 'ssn_idx', hashLength: 16);

        self::assertSame('ssn_idx', $attr->column);
        self::assertSame(16, $attr->hashLength);
    }
}
