<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Http\Message\Response;

use function sprintf;

/**
 * CSV export API endpoint.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class ExportController
{
    public function __construct(
        private StatsServiceInterface $statsService,
        private PageViewRepositoryInterface $pageViewRepository,
    ) {}

    public function export(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $siteId = (string) ($params['site_id'] ?? '');

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        $from = new DateTimeImmutable((string) ($params['from'] ?? '-30 days'));
        $to = new DateTimeImmutable((string) ($params['to'] ?? 'now'));

        $pageViews = $this->pageViewRepository->findBySite($siteId, $from, $to, 10000);

        $csv = "id,pathname,visitor_id,session_id,referrer_source,country_code,device_type,browser,os,screen_width,is_bounce,created_at\n";

        foreach ($pageViews as $pv) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%s\n",
                $pv->id,
                $this->escapeCsv($pv->pathname),
                $pv->visitorId,
                $pv->sessionId,
                $this->escapeCsv($pv->referrerSource),
                $pv->countryCode,
                $pv->deviceType->value,
                $this->escapeCsv($pv->browser),
                $this->escapeCsv($pv->os),
                $pv->screenWidth,
                (int) $pv->isBounce,
                $pv->createdAt->format('c'),
            );
        }

        $filename = sprintf('analytics-export-%s-%s.csv', $from->format('Y-m-d'), $to->format('Y-m-d'));

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-cache',
            ],
            body: $csv,
        );
    }

    /**
     * Escape a value for CSV, including formula injection prevention.
     *
     * Prefixes values starting with =, +, -, @, \t, \r with a tab character
     * to prevent spreadsheet formula interpretation when opened in Excel/Sheets.
     */
    private function escapeCsv(string $value): string
    {
        // Prevent CSV formula injection — prefix dangerous start characters
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            $value = "\t" . $value;
        }

        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
