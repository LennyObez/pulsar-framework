<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\ExportResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceRequest;

#[CoversClass(ExportResourceRequest::class)]
final class ExportResourceRequestTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $request = new ExportResourceRequest(
            resourceName: 'users',
            format: ExportFormat::Csv,
            filters: ['status' => 'active'],
            maxRows: 5000,
        );

        self::assertSame('users', $request->resourceName);
        self::assertSame(ExportFormat::Csv, $request->format);
        self::assertSame(['status' => 'active'], $request->filters);
        self::assertSame(5000, $request->maxRows);
    }

    #[Test]
    public function defaultsEmptyFiltersAndMaxRows(): void
    {
        $request = new ExportResourceRequest(
            resourceName: 'orders',
            format: ExportFormat::Json,
        );

        self::assertSame([], $request->filters);
        self::assertSame(10000, $request->maxRows);
    }
}
