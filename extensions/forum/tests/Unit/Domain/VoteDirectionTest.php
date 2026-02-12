<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\VoteDirection;

final class VoteDirectionTest extends TestCase
{
    #[Test]
    public function upHasPositiveValue(): void
    {
        self::assertSame(1, VoteDirection::Up->value);
    }

    #[Test]
    public function downHasNegativeValue(): void
    {
        self::assertSame(-1, VoteDirection::Down->value);
    }

    #[Test]
    public function hasTwoCases(): void
    {
        self::assertCount(2, VoteDirection::cases());
    }
}
