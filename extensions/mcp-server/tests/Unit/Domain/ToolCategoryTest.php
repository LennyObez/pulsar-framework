<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Domain\ToolCategory;

final class ToolCategoryTest extends TestCase
{
    #[Test]
    public function readHasCorrectValue(): void
    {
        self::assertSame('read', ToolCategory::Read->value);
    }

    #[Test]
    public function actionHasCorrectValue(): void
    {
        self::assertSame('action', ToolCategory::Action->value);
    }

    #[Test]
    public function fromReturnsCorrectCase(): void
    {
        self::assertSame(ToolCategory::Read, ToolCategory::from('read'));
        self::assertSame(ToolCategory::Action, ToolCategory::from('action'));
    }
}
