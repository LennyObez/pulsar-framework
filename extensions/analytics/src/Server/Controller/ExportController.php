<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Http\Message\Response;

use function in_array;
use function is_string;
use function sprintf;
use function str_contains;
use function str_replace;

/**
 * CSV export API endpoint.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class ExportController
{
    public function __construct(
        private PageViewRepositoryInterface $pageViewRepository,
    ) {}

    public function export(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawSiteId */
        $rawSiteId = $params['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        /** @var mixed $rawFrom */
        $rawFrom = $params['from'] ?? null;
        /** @var mixed $rawTo */
        $rawTo = $params['to'] ?? null;
        $from = new DateTimeImmutable(is_string($rawFrom) ? $rawFrom : '-30 days');
        $to = new DateTimeImmutable(is_string($rawTo) ? $rawTo : 'now');

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
        // Prevent CSV formula injection: prefix dangerous start characters
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "\t" . $value;
        }

        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
