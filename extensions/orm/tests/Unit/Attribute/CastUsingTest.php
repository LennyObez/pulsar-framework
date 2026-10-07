<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\CastUsing;
use stdClass;

final class CastUsingTest extends TestCase
{
    #[Test]
    public function storesCasterClass(): void
    {
        $attr = new CastUsing(casterClass: stdClass::class);

        self::assertSame(stdClass::class, $attr->casterClass);
    }
}
