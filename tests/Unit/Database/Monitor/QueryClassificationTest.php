<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Monitor\QueryClassification;

#[CoversClass(QueryClassification::class)]
final class QueryClassificationTest extends TestCase
{
    #[Test]
    public function enumCasesExist(): void
    {
        self::assertSame('select', QueryClassification::Select->value);
        self::assertSame('insert', QueryClassification::Insert->value);
        self::assertSame('update', QueryClassification::Update->value);
        self::assertSame('delete', QueryClassification::Delete->value);
        self::assertSame('ddl', QueryClassification::Ddl->value);

        self::assertCount(5, QueryClassification::cases());
    }
}
