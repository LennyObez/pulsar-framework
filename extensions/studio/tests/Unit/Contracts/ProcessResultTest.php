<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Contracts\ProcessResult;

#[CoversClass(ProcessResult::class)]
final class ProcessResultTest extends TestCase
{
    #[Test]
    public function successFactoryCreatesNonErrorResult(): void
    {
        $result = ProcessResult::success('command output');

        self::assertSame('command output', $result->output);
        self::assertFalse($result->isError);
    }

    #[Test]
    public function errorFactoryCreatesErrorResult(): void
    {
        $result = ProcessResult::error('something failed');

        self::assertSame('something failed', $result->output);
        self::assertTrue($result->isError);
    }

    #[Test]
    public function errorFactoryDefaultsToEmptyOutput(): void
    {
        $result = ProcessResult::error();

        self::assertSame('', $result->output);
        self::assertTrue($result->isError);
    }

    #[Test]
    public function constructorSetsProperties(): void
    {
        $result = new ProcessResult(output: 'raw output', isError: true);

        self::assertSame('raw output', $result->output);
        self::assertTrue($result->isError);
    }

    #[Test]
    public function successWithEmptyOutput(): void
    {
        $result = ProcessResult::success('');

        self::assertSame('', $result->output);
        self::assertFalse($result->isError);
    }
}
