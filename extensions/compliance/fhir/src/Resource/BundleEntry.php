<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Resource;

use Pulsar\Api\Api;

use function is_string;

/**
 * An entry in a FHIR Bundle.
 *
 * @see https://www.hl7.org/fhir/bundle-definitions.html#Bundle.entry
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $resource */
        $resource = $data['resource'] ?? null;
        /** @var array<string, mixed>|null $request */
        $request = $data['request'] ?? null;
        /** @var array<string, mixed>|null $response */
        $response = $data['response'] ?? null;

        return new self(
            fullUrl: is_string($data['fullUrl'] ?? null) ? $data['fullUrl'] : null,
            resource: $resource,
            request: $request,
            response: $response,
        );
    }
}
