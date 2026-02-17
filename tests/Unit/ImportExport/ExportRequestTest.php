<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ImportExport;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ImportExport\ExportRequest;

#[CoversClass(ExportRequest::class)]
final class ExportRequestTest extends TestCase
{
    #[Test]
    public function defaultsToJsonFormat(): void
    {
        $request = new ExportRequest();

        self::assertSame('json', $request->format);
        self::assertSame([], $request->entityTypes);
        self::assertFalse($request->includePii);
        self::assertSame([], $request->filters);
    }

    #[Test]
    #[DataProvider('validFormats')]
    public function acceptsValidFormats(string $format): void
    {
        $request = new ExportRequest(format: $format);

        self::assertSame($format, $request->format);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function validFormats(): iterable
    {
        yield 'json' => ['json'];
        yield 'csv' => ['csv'];
        yield 'xml' => ['xml'];
    }

    #[Test]
    public function rejectsInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid export format 'yaml'");

        new ExportRequest(format: 'yaml');
    }

    #[Test]
    public function fromArrayBuildsCorrectly(): void
    {
        $request = ExportRequest::fromArray([
            'format' => 'csv',
            'entity_types' => ['content', 'menus'],
            'include_pii' => true,
            'filters' => ['status' => 'published'],
        ]);

        self::assertSame('csv', $request->format);
        self::assertSame(['content', 'menus'], $request->entityTypes);
        self::assertTrue($request->includePii);
        self::assertSame(['status' => 'published'], $request->filters);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $request = ExportRequest::fromArray([]);

        self::assertSame('json', $request->format);
        self::assertSame([], $request->entityTypes);
        self::assertFalse($request->includePii);
    }
}
