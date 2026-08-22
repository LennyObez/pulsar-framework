<?php

declare(strict_types=1);

namespace Pulsar\Security\Csp;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Immutable DTO representing a CSP violation report received from a browser.
 *
 * @see https://www.w3.org/TR/CSP3/#violation-reports
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CspViolationReport
{
    public function __construct(
        public string $documentUri,
        public string $violatedDirective,
        public string $effectiveDirective,
        public string $originalPolicy,
        public string $blockedUri,
        public string $sourceFile,
        public int $lineNumber,
        public int $columnNumber,
        public int $statusCode,
        public string $disposition,
        public string $referrer,
        public string $scriptSample,
        public DateTimeImmutable $receivedAt,
    ) {}

    /**
     * Parse from a CSP report JSON body (report-uri format).
     *
     * @param array<mixed, mixed> $data The `csp-report` object from the browser
     */
    #[NoDiscard]
    public static function fromReportUri(array $data): self
    {
        return new self(
            documentUri: self::str($data, 'document-uri'),
            violatedDirective: self::str($data, 'violated-directive'),
            effectiveDirective: self::str($data, 'effective-directive'),
            originalPolicy: self::str($data, 'original-policy'),
            blockedUri: self::str($data, 'blocked-uri'),
            sourceFile: self::str($data, 'source-file'),
            lineNumber: is_numeric($data['line-number'] ?? null) ? (int) $data['line-number'] : 0,
            columnNumber: is_numeric($data['column-number'] ?? null) ? (int) $data['column-number'] : 0,
            statusCode: is_numeric($data['status-code'] ?? null) ? (int) $data['status-code'] : 0,
            disposition: self::str($data, 'disposition'),
            referrer: self::str($data, 'referrer'),
            scriptSample: self::str($data, 'script-sample'),
            receivedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Parse from a Reporting API v1 format (report-to).
     *
     * @param array<mixed, mixed> $data The `body` object from a Reporting API report
     */
    #[NoDiscard]
    public static function fromReportTo(array $data): self
    {
        return new self(
            documentUri: self::str($data, 'documentURL'),
            violatedDirective: self::str($data, 'effectiveDirective'),
            effectiveDirective: self::str($data, 'effectiveDirective'),
            originalPolicy: self::str($data, 'originalPolicy'),
            blockedUri: self::str($data, 'blockedURL'),
            sourceFile: self::str($data, 'sourceFile'),
            lineNumber: is_numeric($data['lineNumber'] ?? null) ? (int) $data['lineNumber'] : 0,
            columnNumber: is_numeric($data['columnNumber'] ?? null) ? (int) $data['columnNumber'] : 0,
            statusCode: is_numeric($data['statusCode'] ?? null) ? (int) $data['statusCode'] : 0,
            disposition: self::str($data, 'disposition'),
            referrer: self::str($data, 'referrer'),
            scriptSample: self::str($data, 'sample'),
            receivedAt: new DateTimeImmutable(),
        );
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        /** @var mixed $value */
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
