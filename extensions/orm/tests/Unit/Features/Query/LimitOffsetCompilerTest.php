<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Features\Query\LimitOffsetCompiler;
use Pulsar\Extension\Orm\Internal\Compiler\SqliteDialect;

final class LimitOffsetCompilerTest extends TestCase
{
    private LimitOffsetCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new LimitOffsetCompiler(new SqliteDialect());
    }

    #[Test]
    public function compileWithBothLimitAndOffset(): void
    {
        $result = $this->compiler->compile(10, 20);

        self::assertStringContainsString('LIMIT', $result);
        self::assertStringContainsString('OFFSET', $result);
    }

    #[Test]
    public function compileWithLimitOnly(): void
    {
        $result = $this->compiler->compile(50, null);

        self::assertStringContainsString('LIMIT', $result);
    }

    #[Test]
    public function compileWithNullReturnsEmpty(): void
    {
        $result = $this->compiler->compile(null, null);

        self::assertSame('', $result);
    }
}
