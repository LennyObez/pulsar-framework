<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

#[CoversNothing]
final class ResourceOperationTest extends TestCase
{
    #[Test]
    #[DataProvider('operationCaseProvider')]
    public function operationHasCorrectValue(ResourceOperation $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{ResourceOperation, string}>
     */
    public static function operationCaseProvider(): iterable
    {
        yield 'List' => [ResourceOperation::List, 'list'];
        yield 'View' => [ResourceOperation::View, 'view'];
        yield 'Create' => [ResourceOperation::Create, 'create'];
        yield 'Update' => [ResourceOperation::Update, 'update'];
        yield 'Delete' => [ResourceOperation::Delete, 'delete'];
        yield 'Export' => [ResourceOperation::Export, 'export'];
        yield 'BulkAction' => [ResourceOperation::BulkAction, 'bulk_action'];
    }

    #[Test]
    public function casesCount(): void
    {
        self::assertCount(7, ResourceOperation::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(ResourceOperation::tryFrom('unknown'));
    }
}
