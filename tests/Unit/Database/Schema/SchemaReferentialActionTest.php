<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaReferentialAction;
use ValueError;

#[CoversNothing]
final class SchemaReferentialActionTest extends TestCase
{
    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, SchemaReferentialAction::cases());
    }

    #[Test]
    #[DataProvider('actionProvider')]
    public function backedValuesMatchSqlKeywords(SchemaReferentialAction $action, string $expectedSql): void
    {
        self::assertSame($expectedSql, $action->value);
    }

    /**
     * @return iterable<string, array{SchemaReferentialAction, string}>
     */
    public static function actionProvider(): iterable
    {
        yield 'Restrict' => [SchemaReferentialAction::Restrict, 'RESTRICT'];
        yield 'Cascade' => [SchemaReferentialAction::Cascade, 'CASCADE'];
        yield 'SetNull' => [SchemaReferentialAction::SetNull, 'SET NULL'];
        yield 'NoAction' => [SchemaReferentialAction::NoAction, 'NO ACTION'];
    }

    #[Test]
    public function constructsFromBackedValue(): void
    {
        self::assertSame(SchemaReferentialAction::Cascade, SchemaReferentialAction::from('CASCADE'));
        self::assertSame(SchemaReferentialAction::SetNull, SchemaReferentialAction::from('SET NULL'));
    }

    #[Test]
    public function fromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);

        SchemaReferentialAction::from('DROP');
    }

    #[Test]
    public function tryFromReturnsNullForUnknown(): void
    {
        self::assertNull(SchemaReferentialAction::tryFrom('TRUNCATE'));
    }
}
