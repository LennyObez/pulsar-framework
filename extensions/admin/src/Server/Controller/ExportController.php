<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use function is_string;

use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceHandler;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceRequest;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Controller for exporting resource data.
 */
#[Internal]
final readonly class ExportController
{
    public function __construct(
        private readonly ExportResourceHandler $handler,
    ) {}

    public function export(Request $request, string $resource): Response
    {
        $formatStr = $request->query('format');
        $format = is_string($formatStr) ? ExportFormat::tryFrom($formatStr) : null;
        if ($format === null) {
            $format = ExportFormat::Csv;
        }

        $filters = [];
        $rawFilters = $request->query('filters');
        if (is_string($rawFilters)) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawFilters, true) ?? [];
            $filters = $decoded;
        }

        $result = $this->handler->execute(new ExportResourceRequest(
            resourceName: $resource,
            format: $format,
            filters: $filters,
        ));

        return new Response(
            body: $result->content,
            status: ResponseStatus::OK,
            headers: new HeaderBag([
                'Content-Type' => $result->mimeType,
                'Content-Disposition' => "attachment; filename=\"$result->filename\"",
                'X-Evidence-Hash' => $result->evidenceHash,
                'X-Export-Row-Count' => (string) $result->rowCount,
            ]),
        );
    }
}
