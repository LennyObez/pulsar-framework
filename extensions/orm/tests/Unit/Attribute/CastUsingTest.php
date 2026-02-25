<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\CastUsing;

final class CastUsingTest extends TestCase
{
    #[Test]
    public function storesCasterClass(): void
    {
        $attr = new CastUsing(casterClass: 'App\\Casters\\MoneyCaster');

        self::assertSame('App\\Casters\\MoneyCaster', $attr->casterClass);
    }
}
