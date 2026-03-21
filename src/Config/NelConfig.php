<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Typed configuration DTO for Network Error Logging (NEL) headers.
 *
 * NEL allows the browser to report network-level errors back to a
 * configured endpoint, even when the request never reaches the server.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class NelConfig
{
    public function __construct(
        public bool $enabled = false,
        public string $reportTo = 'default',
        public int $maxAge = 86400,
        public bool $includeSubdomains = false,
        public float $successFraction = 0.0,
        public float $failureFraction = 1.0,
    ) {}

    /**
     * Build the NEL header value as a JSON object.
     */
    #[NoDiscard]
    public function toHeaderValue(): string
    {
        if (!$this->enabled) {
            return '';
        }

        $nel = [
            'report_to' => $this->reportTo,
            'max_age' => $this->maxAge,
            'include_subdomains' => $this->includeSubdomains,
            'success_fraction' => $this->successFraction,
            'failure_fraction' => $this->failureFraction,
        ];

        return json_encode($nel, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build the Report-To header value for the NEL endpoint group.
     */
    #[NoDiscard]
    public function toReportToHeaderValue(string $endpointUrl): string
    {
        if (!$this->enabled || $endpointUrl === '') {
            return '';
        }

        $reportTo = [
            'group' => $this->reportTo,
            'max_age' => $this->maxAge,
            'endpoints' => [
                ['url' => $endpointUrl],
            ],
            'include_subdomains' => $this->includeSubdomains,
        ];

        return json_encode($reportTo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $data Raw `nel` sub-array from config
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawMaxAge = $data['max_age'] ?? 86400;
        $maxAge = is_int($rawMaxAge) ? $rawMaxAge : (int) (is_numeric($rawMaxAge) ? $rawMaxAge : 86400);

        $rawSuccessFraction = $data['success_fraction'] ?? 0.0;
        $successFraction = is_numeric($rawSuccessFraction) ? (float) $rawSuccessFraction : 0.0;

        $rawFailureFraction = $data['failure_fraction'] ?? 1.0;
        $failureFraction = is_numeric($rawFailureFraction) ? (float) $rawFailureFraction : 1.0;

        $rawReportTo = $data['report_to'] ?? 'default';

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            reportTo: is_string($rawReportTo) ? $rawReportTo : 'default',
            maxAge: $maxAge,
            includeSubdomains: (bool) ($data['include_subdomains'] ?? false),
            successFraction: $successFraction,
            failureFraction: $failureFraction,
        );
    }
}
