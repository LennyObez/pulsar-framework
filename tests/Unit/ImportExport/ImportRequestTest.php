<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ImportExport;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ImportExport\DuplicateStrategy;
use Pulsar\ImportExport\ImportRequest;

#[CoversClass(ImportRequest::class)]
final class ImportRequestTest extends TestCase
{
    #[Test]
    public function constructsWithValidContent(): void
    {
        $request = new ImportRequest(content: '{"data": []}');

        self::assertSame('{"data": []}', $request->content);
        self::assertSame('json', $request->format);
        self::assertTrue($request->dryRun);
        self::assertSame(DuplicateStrategy::Skip, $request->duplicateStrategy);
    }

    #[Test]
    public function rejectsEmptyContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Import content must not be empty');

        new ImportRequest(content: '');
    }

    #[Test]
    public function rejectsInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("Invalid import format 'toml'");

        new ImportRequest(content: 'data', format: 'toml');
    }

    #[Test]
    public function acceptsAllValidFormats(): void
    {
        foreach (['json', 'csv', 'xml'] as $format) {
            $request = new ImportRequest(content: 'content', format: $format);
            self::assertSame($format, $request->format);
        }
    }

    #[Test]
    public function acceptsDuplicateStrategies(): void
    {
        foreach (DuplicateStrategy::cases() as $strategy) {
            $request = new ImportRequest(
                content: 'data',
                duplicateStrategy: $strategy,
            );
            self::assertSame($strategy, $request->duplicateStrategy);
        }
    }

    #[Test]
    public function executeModeSetssDryRunFalse(): void
    {
        $request = new ImportRequest(content: 'data', dryRun: false);

        self::assertFalse($request->dryRun);
    }
}
