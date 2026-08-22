<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;

#[CoversNothing]
final class ToolCategoryTest extends TestCase
{
    #[Test]
    public function readCategoryHasCorrectValue(): void
    {
        self::assertSame('read', ToolCategory::Read->value);
    }

    #[Test]
    public function actionCategoryHasCorrectValue(): void
    {
        self::assertSame('action', ToolCategory::Action->value);
    }

    #[Test]
    public function fromStringProducesCorrectCase(): void
    {
        self::assertSame(ToolCategory::Read, ToolCategory::from('read'));
        self::assertSame(ToolCategory::Action, ToolCategory::from('action'));
    }
}
