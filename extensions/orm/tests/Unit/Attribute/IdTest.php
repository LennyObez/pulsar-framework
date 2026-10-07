<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\Id;

final class IdTest extends TestCase
{
    #[Test]
    public function defaultAutoIncrement(): void
    {
        $id = new Id();

        self::assertTrue($id->autoIncrement);
    }

    #[Test]
    public function disableAutoIncrement(): void
    {
        $id = new Id(autoIncrement: false);

        self::assertFalse($id->autoIncrement);
    }
}
