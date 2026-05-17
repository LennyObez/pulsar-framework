<?php

declare(strict_types=1);

namespace Pulsar\Security\Csp;

use JsonException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

use function is_array;
use function json_decode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * Built-in endpoint for receiving CSP violation reports.
 *
 * Accepts both legacy report-uri format (application/csp-report)
 * and Reporting API v1 format (application/reports+json).
 *
 * Wire this as a route handler at the path configured in CSP report-uri/report-to.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CspReportEndpoint implements RequestHandlerInterface
{
    public function __construct(
        private CspReportCollectorInterface $collector,
        private ?LoggerInterface $logger = null,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return Response::json(
                ['error' => 'Method Not Allowed'],
                ResponseStatus::MethodNotAllowed->value,
            );
        }

        $contentType = $request->getHeaderLine('Content-Type');
        $body = (string) $request->getBody();

        if ($body === '') {
            return Response::json(
                ['error' => 'Empty report body'],
                ResponseStatus::BadRequest->value,
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return Response::json(
                ['error' => 'Invalid JSON'],
                ResponseStatus::BadRequest->value,
            );
        }

        if (!is_array($decoded)) {
            return Response::json(
                ['error' => 'Invalid report format'],
                ResponseStatus::BadRequest->value,
            );
        }

        if (str_contains($contentType, 'csp-report') && is_array($decoded['csp-report'] ?? null)) {
            /** @var array<string, mixed> $reportData */
            $reportData = $decoded['csp-report'];
            $report = CspViolationReport::fromReportUri($reportData);
            $this->collector->collect($report);
            $this->logViolation($report);
        } elseif (str_contains($contentType, 'reports+json') || is_array($decoded[0] ?? null)) {
            // Reporting API sends an array of reports
            /** @var list<array<string, mixed>> $reports */
            $reports = isset($decoded[0]) ? $decoded : [$decoded];

            foreach ($reports as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                /** @var array<string, mixed> $entryBody */
                $entryBody = is_array($entry['body'] ?? null) ? $entry['body'] : $entry;
                $report = CspViolationReport::fromReportTo($entryBody);
                $this->collector->collect($report);
                $this->logViolation($report);
            }
        } else {
            // Best-effort: try report-uri format on the raw object
            $report = CspViolationReport::fromReportUri($decoded);
            $this->collector->collect($report);
            $this->logViolation($report);
        }

        return new Response(statusCode: ResponseStatus::NoContent->value);
    }

    private function logViolation(CspViolationReport $report): void
    {
        $this->logger?->warning('CSP violation: {directive} blocked {uri} on {document}', [
            'directive' => $report->effectiveDirective,
            'uri' => $report->blockedUri,
            'document' => $report->documentUri,
            'source_file' => $report->sourceFile,
            'line' => $report->lineNumber,
        ]);
    }
}
