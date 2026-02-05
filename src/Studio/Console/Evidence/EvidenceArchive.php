<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Evidence;

use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use Pulsar\Api\Internal;

/**
 * Readonly DTO representing an exported evidence archive.
 *
 * Contains events, chain links, manifest metadata, and optional MAC.
 */
#[Internal]
final readonly class EvidenceArchive
{
    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $chainLinks
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        public array $events,
        public array $chainLinks,
        public array $manifest,
        public ?string $mac = null,
    ) {}

    /**
     * Serialize the archive to a JSON string.
     */
    public function toJson(): string
    {
        return json_encode([
            'events' => $this->events,
            'chain' => $this->chainLinks,
            'manifest' => $this->manifest,
            'mac' => $this->mac,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Deserialize an archive from a JSON string.
     */
    public static function fromJson(string $json): self
    {
        /** @var array{events: list<array<string, mixed>>, chain: list<array<string, mixed>>, manifest: array<string, mixed>, mac: ?string} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            events: $data['events'],
            chainLinks: $data['chain'],
            manifest: $data['manifest'],
            mac: $data['mac'] ?? null,
        );
    }
}
