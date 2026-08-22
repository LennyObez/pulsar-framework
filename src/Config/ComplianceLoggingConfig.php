<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Typed configuration for the compliance log sink
 * (`config/observability.php` -> `logging.compliance`).
 *
 * When enabled, every log entry is routed through the regulation-specific
 * formatters (GDPR / HIPAA pseudonymization, PCI-DSS / SOX masking) for the
 * selected frameworks and written to a dedicated durable file, optionally
 * encrypted at rest when the security encryptor is available.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class ComplianceLoggingConfig implements ReportsUnknownKeys
{
    public const array KNOWN_FRAMEWORKS = ['gdpr', 'hipaa', 'pci-dss', 'sox'];

    /** Keys read from the `logging.compliance` sub-array of config/observability.php. */
    private const array KNOWN_KEYS = ['enabled', 'frameworks', 'path'];

    /** @var list<string> */
    public array $frameworks;

    /**
     * @param list<string> $frameworks Compliance frameworks to format for;
     *        empty selects every known framework (maximal masking).
     * @param list<string> $unknownKeys Keys present in the raw `logging.compliance`
     *        array that this DTO does not read.
     */
    public function __construct(
        public bool $enabled = false,
        array $frameworks = [],
        public string $path = 'var/logs/compliance.log',
        public array $unknownKeys = [],
    ) {
        $this->frameworks = $frameworks !== [] ? $frameworks : self::KNOWN_FRAMEWORKS;
    }

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     frameworks?: list<string>,
     *     path?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $frameworks = [];

        foreach ($data['frameworks'] ?? [] as $framework) {
            if (is_string($framework) && $framework !== '') {
                $frameworks[] = $framework;
            }
        }

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            frameworks: $frameworks,
            path: $data['path'] ?? 'var/logs/compliance.log',
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
