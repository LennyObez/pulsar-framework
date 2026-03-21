<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Interoperability;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\DataAct\Config\DataActConfig;

use function in_array;

/**
 * Interoperability profile declaration per Data Act Articles 28-30.
 *
 * Cloud service providers and data processing services must publish
 * interoperability specifications including supported data formats,
 * API standards, and open interfaces.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InteroperabilityProfile
{
    /**
     * @param list<string> $supportedFormats  Supported data exchange formats
     * @param list<string> $apiStandards      Supported API standards (e.g., OpenAPI, GraphQL)
     * @param list<string> $openInterfaces    Published open interface specifications
     * @param string       $complianceLevel   Self-declared compliance level
     */
    public function __construct(
        public array $supportedFormats,
        public array $apiStandards,
        public array $openInterfaces,
        public string $complianceLevel = 'basic',
    ) {}

    /**
     * Build an interoperability profile based on platform configuration.
     */
    #[NoDiscard]
    public static function forPlatform(DataActConfig $config): self
    {
        $formats = ['json'];

        if ($config->defaultExportFormat !== 'json') {
            $formats[] = $config->defaultExportFormat;
        }

        $formats[] = 'csv';
        $formats[] = 'xml';

        return new self(
            supportedFormats: $formats,
            apiStandards: ['openapi-3.1'],
            openInterfaces: ['rest-api'],
            complianceLevel: $config->isCloudProvider() ? 'enhanced' : 'basic',
        );
    }

    /**
     * Whether the profile supports a given data format.
     */
    #[NoDiscard]
    public function supportsFormat(string $format): bool
    {
        return in_array($format, $this->supportedFormats, true);
    }

    /**
     * Serialize the profile for API responses or transparency reporting.
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'supported_formats' => $this->supportedFormats,
            'api_standards' => $this->apiStandards,
            'open_interfaces' => $this->openInterfaces,
            'compliance_level' => $this->complianceLevel,
        ];
    }
}
