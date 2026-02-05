<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

#[CoversClass(ResourceOperation::class)]
final class ResourceOperationTest extends TestCase
{
    #[Test]
    public function listValue(): void
    {
        self::assertSame('list', ResourceOperation::List->value);
    }

    #[Test]
    public function viewValue(): void
    {
        self::assertSame('view', ResourceOperation::View->value);
    }

    #[Test]
    public function createValue(): void
    {
        self::assertSame('create', ResourceOperation::Create->value);
    }

    #[Test]
    public function updateValue(): void
    {
        self::assertSame('update', ResourceOperation::Update->value);
    }

    #[Test]
    public function deleteValue(): void
    {
        self::assertSame('delete', ResourceOperation::Delete->value);
    }

    #[Test]
    public function exportValue(): void
    {
        self::assertSame('export', ResourceOperation::Export->value);
    }

    #[Test]
    public function bulkActionValue(): void
    {
        self::assertSame('bulk_action', ResourceOperation::BulkAction->value);
    }

    #[Test]
    public function allCasesArePresent(): void
    {
        self::assertCount(7, ResourceOperation::cases());
    }
}
