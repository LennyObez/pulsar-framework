<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaCollation;
use ValueError;

#[CoversNothing]
final class SchemaCollationTest extends TestCase
{
    /**
     * The backing value names the intent, not a MySQL collation.
     *
     * It reaches the wire: the admin schema editor posts a column definition as JSON and
     * rebuilds it with `from()`. A value like `utf8mb4_bin` there would put an engine's
     * vocabulary into a payload every engine has to accept.
     */
    #[Test]
    public function backedValueNamesTheIntent(): void
    {
        self::assertSame('exact', SchemaCollation::Exact->value);
        self::assertSame(SchemaCollation::Exact, SchemaCollation::from('exact'));
    }

    /**
     * Nothing outside the allowlist gets in.
     *
     * A collation name is interpolated into DDL that cannot be parameterised, so the enum
     * is the boundary that keeps a request from becoming arbitrary SQL — the same posture
     * {@see \Pulsar\Database\Schema\SchemaDefaultExpression} takes for default clauses.
     */
    #[Test]
    public function anArbitraryCollationNameIsRefused(): void
    {
        $this->expectException(ValueError::class);

        SchemaCollation::from('utf8mb4_general_ci');
    }

    #[Test]
    public function tryFromReturnsNullForAnUnknownValue(): void
    {
        self::assertNull(SchemaCollation::tryFrom('binary'));
    }

    /**
     * One case, deliberately.
     *
     * A case-insensitive counterpart is not offered: PostgreSQL has no table-level
     * spelling for it and SQLite's NOCASE folds ASCII only, so the layer would be
     * accepting a request it could honour on one engine of three. If a second case ever
     * lands here it has to be one all three can keep.
     */
    #[Test]
    public function offersOnlyTheIntentEveryEngineCanHonour(): void
    {
        self::assertSame([SchemaCollation::Exact], SchemaCollation::cases());
    }
}
