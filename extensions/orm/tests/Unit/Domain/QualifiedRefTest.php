<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\QualifiedRef;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

final class QualifiedRefTest extends TestCase
{
    #[Test]
    public function parseValidQualifiedRef(): void
    {
        $ref = QualifiedRef::parse('t0.user_id');

        self::assertSame('t0', $ref->alias);
        self::assertSame('user_id', $ref->column);
    }

    #[Test]
    public function parseThrowsForUnqualifiedRef(): void
    {
        $this->expectException(QueryBuilderException::class);

        (void) QualifiedRef::parse('just_column');
    }

    #[Test]
    public function ofCreatesValidRef(): void
    {
        $ref = QualifiedRef::of('users', 'email');

        self::assertSame('users', $ref->alias);
        self::assertSame('email', $ref->column);
    }

    #[Test]
    public function ofRejectsInvalidAlias(): void
    {
        $this->expectException(QueryBuilderException::class);

        (void) QualifiedRef::of('1bad', 'column');
    }

    #[Test]
    public function ofRejectsInvalidColumn(): void
    {
        $this->expectException(QueryBuilderException::class);

        (void) QualifiedRef::of('alias', 'bad-col');
    }

    #[Test]
    public function toStringFormatsCorrectly(): void
    {
        $ref = new QualifiedRef('t0', 'name');

        self::assertSame('t0.name', $ref->toString());
    }
}
