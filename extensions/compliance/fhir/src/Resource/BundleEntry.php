<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

/**
 * An entry in a FHIR Bundle.
 *
 * @see https://www.hl7.org/fhir/bundle-definitions.html#Bundle.entry
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BundleEntry
{
    /**
     * @param array<string, mixed>|null $resource The resource in this entry (raw array for flexibility)
     * @param array<string, mixed>|null $request  For batch/transaction bundles: the HTTP request details
     * @param array<string, mixed>|null $response For batch-response/transaction-response: HTTP response details
     */
    public function __construct(
        public ?string $fullUrl = null,
        public ?array $resource = null,
        public ?array $request = null,
        public ?array $response = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->fullUrl !== null) {
            $data['fullUrl'] = $this->fullUrl;
        }

        if ($this->resource !== null) {
            $data['resource'] = $this->resource;
        }

        if ($this->request !== null) {
            $data['request'] = $this->request;
        }

        if ($this->response !== null) {
            $data['response'] = $this->response;
        }

        return $data;
    }

    /**
     * @param array{
     *     fullUrl?: string|null,
     *     resource?: array<string, mixed>|null,
     *     request?: array<string, mixed>|null,
     *     response?: array<string, mixed>|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            fullUrl: $data['fullUrl'] ?? null,
            resource: $data['resource'] ?? null,
            request: $data['request'] ?? null,
            response: $data['response'] ?? null,
        );
    }
}
