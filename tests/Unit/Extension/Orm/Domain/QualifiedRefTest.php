<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\QualifiedRef;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

#[CoversClass(QualifiedRef::class)]
final class QualifiedRefTest extends TestCase
{
    #[Test]
    public function parseValidQualifiedRef(): void
    {
        $ref = QualifiedRef::parse('u.id');

        self::assertSame('u', $ref->alias);
        self::assertSame('id', $ref->column);
        self::assertSame('u.id', $ref->toString());
    }

    #[Test]
    public function parseWithLongerNames(): void
    {
        $ref = QualifiedRef::parse('users.email_address');

        self::assertSame('users', $ref->alias);
        self::assertSame('email_address', $ref->column);
    }

    #[Test]
    public function parseRejectsUnqualifiedReference(): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessageIsOrContains('qualified references');

        $_ = QualifiedRef::parse('id');
    }

    #[Test]
    public function parseRejectsEmptySegments(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = QualifiedRef::parse('.id');
    }

    #[Test]
    public function parseRejectsInvalidAlias(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = QualifiedRef::parse('1bad.id');
    }

    #[Test]
    public function parseRejectsInvalidColumn(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = QualifiedRef::parse('u.1bad');
    }

    #[Test]
    public function ofCreatesValidRef(): void
    {
        $ref = QualifiedRef::of('t0', 'name');

        self::assertSame('t0', $ref->alias);
        self::assertSame('name', $ref->column);
        self::assertSame('t0.name', $ref->toString());
    }

    #[Test]
    public function ofRejectsInvalidAlias(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = QualifiedRef::of('1bad', 'name');
    }

    #[Test]
    public function ofRejectsInvalidColumn(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = QualifiedRef::of('t0', 'bad-col');
    }
}
