<?php

declare(strict_types=1);

namespace Pulsar\Build;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Metadata about a build: when it was built, with what PHP and Pulsar versions, and on what host.
 */
#[Api(since: '1.0.0')]
final readonly class BuildMetadata
{
    public function __construct(
        public string $builtAt,
        public string $phpVersion,
        public string $pulsarVersion,
        public ?string $host = null,
    ) {}

    /**
     * Create from array data.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            builtAt: is_string($data['builtAt'] ?? null) ? $data['builtAt'] : '',
            phpVersion: is_string($data['phpVersion'] ?? null) ? $data['phpVersion'] : '',
            pulsarVersion: is_string($data['pulsarVersion'] ?? null) ? $data['pulsarVersion'] : '',
            host: is_string($data['host'] ?? null) ? $data['host'] : null,
        );
    }

    /**
     * Export to array representation.
     *
     * @return array{builtAt: string, phpVersion: string, pulsarVersion: string, host: ?string}
     */
    public function toArray(): array
    {
        return [
            'builtAt' => $this->builtAt,
            'phpVersion' => $this->phpVersion,
            'pulsarVersion' => $this->pulsarVersion,
            'host' => $this->host,
        ];
    }

    /**
     * Export as deterministic JSON.
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
