<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceHandler;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceRequest;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

use function is_string;

/**
 * Controller for exporting resource data.
 */
#[Internal]
final readonly class ExportController
{
    public function __construct(
        private ExportResourceHandler $handler,
    ) {}

    public function export(ServerRequestInterface $request, string $resource): Response
    {
        $queryParams = $request->getQueryParams();

        $formatStr = $queryParams['format'] ?? null;
        $format = is_string($formatStr) ? ExportFormat::tryFrom($formatStr) : null;
        if ($format === null) {
            $format = ExportFormat::Csv;
        }

        $filters = [];
        $rawFilters = $queryParams['filters'] ?? null;
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
            statusCode: ResponseStatus::OK->value,
            headers: [
                'Content-Type' => $result->mimeType,
                'Content-Disposition' => "attachment; filename=\"$result->filename\"",
                'X-Evidence-Hash' => $result->evidenceHash,
                'X-Export-Row-Count' => (string) $result->rowCount,
            ],
            body: $result->content,
        );
    }
}
