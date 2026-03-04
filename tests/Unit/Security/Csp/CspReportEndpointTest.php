<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Csp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Csp\CspReportEndpoint;
use Pulsar\Security\Csp\CspViolationReport;
use Pulsar\Security\Csp\InMemoryCspReportCollector;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(CspReportEndpoint::class)]
#[CoversClass(CspViolationReport::class)]
#[CoversClass(InMemoryCspReportCollector::class)]
final class CspReportEndpointTest extends TestCase
{
    private InMemoryCspReportCollector $collector;
    private CspReportEndpoint $endpoint;

    protected function setUp(): void
    {
        $this->collector = new InMemoryCspReportCollector();
        $this->endpoint = new CspReportEndpoint($this->collector);
    }

    #[Test]
    public function rejects_non_post_requests(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/.well-known/csp-report');
        $response = $this->endpoint->handle($request);

        self::assertSame(ResponseStatus::MethodNotAllowed->value, $response->getStatusCode());
    }

    #[Test]
    public function rejects_empty_body(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/.well-known/csp-report',
            headers: ['Content-Type' => 'application/csp-report'],
            body: '',
        );
        $response = $this->endpoint->handle($request);

        self::assertSame(ResponseStatus::BadRequest->value, $response->getStatusCode());
    }

    #[Test]
    public function rejects_invalid_json(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/.well-known/csp-report',
            headers: ['Content-Type' => 'application/csp-report'],
            body: 'not json',
        );
        $response = $this->endpoint->handle($request);

        self::assertSame(ResponseStatus::BadRequest->value, $response->getStatusCode());
    }

    #[Test]
    public function processes_report_uri_format(): void
    {
        $body = json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/page',
                'violated-directive' => 'script-src',
                'effective-directive' => 'script-src',
                'original-policy' => "script-src 'self'",
                'blocked-uri' => 'https://evil.com/script.js',
                'source-file' => 'https://example.com/page',
                'line-number' => 42,
                'column-number' => 10,
                'status-code' => 200,
                'disposition' => 'enforce',
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/.well-known/csp-report',
            headers: ['Content-Type' => 'application/csp-report'],
            body: $body,
        );

        $response = $this->endpoint->handle($request);

        self::assertSame(ResponseStatus::NoContent->value, $response->getStatusCode());
        self::assertCount(1, $this->collector->all());

        $report = $this->collector->all()[0];
        self::assertSame('https://example.com/page', $report->documentUri);
        self::assertSame('script-src', $report->effectiveDirective);
        self::assertSame('https://evil.com/script.js', $report->blockedUri);
        self::assertSame(42, $report->lineNumber);
    }

    #[Test]
    public function processes_reporting_api_format(): void
    {
        $body = json_encode([
            [
                'type' => 'csp-violation',
                'body' => [
                    'documentURL' => 'https://example.com/',
                    'effectiveDirective' => 'style-src',
                    'originalPolicy' => "style-src 'self'",
                    'blockedURL' => 'https://cdn.example.com/style.css',
                    'sourceFile' => '',
                    'lineNumber' => 0,
                    'columnNumber' => 0,
                    'statusCode' => 200,
                    'disposition' => 'enforce',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/.well-known/csp-report',
            headers: ['Content-Type' => 'application/reports+json'],
            body: $body,
        );

        $response = $this->endpoint->handle($request);

        self::assertSame(ResponseStatus::NoContent->value, $response->getStatusCode());
        self::assertCount(1, $this->collector->all());
        self::assertSame('style-src', $this->collector->all()[0]->effectiveDirective);
    }

    #[Test]
    public function aggregates_by_directive(): void
    {
        $this->collector->collect(CspViolationReport::fromReportUri([
            'effective-directive' => 'script-src',
            'blocked-uri' => 'https://evil.com/a.js',
        ]));
        $this->collector->collect(CspViolationReport::fromReportUri([
            'effective-directive' => 'script-src',
            'blocked-uri' => 'https://evil.com/b.js',
        ]));
        $this->collector->collect(CspViolationReport::fromReportUri([
            'effective-directive' => 'style-src',
            'blocked-uri' => 'https://cdn.evil.com/style.css',
        ]));

        $agg = $this->collector->aggregateByDirective();

        self::assertSame(2, $agg['script-src']);
        self::assertSame(1, $agg['style-src']);
    }

    #[Test]
    public function aggregates_by_blocked_uri(): void
    {
        $this->collector->collect(CspViolationReport::fromReportUri([
            'effective-directive' => 'script-src',
            'blocked-uri' => 'https://evil.com/a.js',
        ]));
        $this->collector->collect(CspViolationReport::fromReportUri([
            'effective-directive' => 'script-src',
            'blocked-uri' => 'https://evil.com/a.js',
        ]));

        $agg = $this->collector->aggregateByBlockedUri();

        self::assertSame(2, $agg['https://evil.com/a.js']);
    }

    #[Test]
    public function empty_directives_skipped_in_aggregation(): void
    {
        $this->collector->collect(CspViolationReport::fromReportUri([]));

        self::assertSame([], $this->collector->aggregateByDirective());
        self::assertSame([], $this->collector->aggregateByBlockedUri());
    }
}
