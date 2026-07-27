<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
final readonly class NelConfig implements ReportsUnknownKeys
{
    /** Keys read from the `headers.nel` sub-array of config/security.php. */
    private const array KNOWN_KEYS = [
        'enabled', 'report_to', 'max_age', 'include_subdomains',
        'success_fraction', 'failure_fraction',
    ];

    /**
     * @param list<string> $unknownKeys Keys present in the raw `nel` array that this
     *     DTO does not read. Note NEL spells it `include_subdomains` while HSTS uses
     *     `include_sub_domains` (per their respective specs) — writing one section's
     *     spelling in the other is precisely the typo this reporting catches.
     */
    public function __construct(
        public bool $enabled = false,
        public string $reportTo = 'default',
        public int $maxAge = 86400,
        public bool $includeSubdomains = false,
        public float $successFraction = 0.0,
        public float $failureFraction = 1.0,
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

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
     * @param array{
     *     enabled?: bool|int|string,
     *     report_to?: string,
     *     max_age?: int,
     *     include_subdomains?: bool|int|string,
     *     success_fraction?: float|int,
     *     failure_fraction?: float|int,
     * } $data Raw `nel` sub-array from config
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            reportTo: Coerce::string($data['report_to'] ?? null, 'default'),
            maxAge: Coerce::int($data['max_age'] ?? null, 86400),
            includeSubdomains: (bool) ($data['include_subdomains'] ?? false),
            successFraction: Coerce::float($data['success_fraction'] ?? null, 0.0),
            failureFraction: Coerce::float($data['failure_fraction'] ?? null, 1.0),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
