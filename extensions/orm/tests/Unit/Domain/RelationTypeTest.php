<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\RelationType;

final class RelationTypeTest extends TestCase
{
    #[Test]
    public function allCasesHaveBackingValues(): void
    {
        self::assertSame('belongs_to', RelationType::BelongsTo->value);
        self::assertSame('has_one', RelationType::HasOne->value);
        self::assertSame('has_many', RelationType::HasMany->value);
        self::assertSame('belongs_to_many', RelationType::BelongsToMany->value);
    }

    #[Test]
    public function totalCaseCount(): void
    {
        self::assertCount(4, RelationType::cases());
    }
}
