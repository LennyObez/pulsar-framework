<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\AttributionModel;

final class AttributionModelTest extends TestCase
{
    #[Test]
    public function all_cases(): void
    {
        $cases = AttributionModel::cases();
        self::assertCount(4, $cases);

        self::assertSame('first_touch', AttributionModel::FirstTouch->value);
        self::assertSame('last_touch', AttributionModel::LastTouch->value);
        self::assertSame('linear', AttributionModel::Linear->value);
        self::assertSame('time_decay', AttributionModel::TimeDecay->value);
    }

    #[Test]
    public function from_string(): void
    {
        self::assertSame(AttributionModel::Linear, AttributionModel::from('linear'));
    }

    #[Test]
    public function try_from_invalid_returns_null(): void
    {
        self::assertNull(AttributionModel::tryFrom('unknown'));
    }
}
