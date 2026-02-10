<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Evidence;

use InvalidArgumentException;
use JsonException;
use NoDiscard;
use Pulsar\Api\Internal;

use function is_string;
use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

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
     *
     * @throws JsonException
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
     *
     * @throws InvalidArgumentException If JSON is invalid or required keys are missing
     */
    #[NoDiscard]
    public static function fromJson(string $json): self
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($data['events'], $data['chain'], $data['manifest'])) {
            throw new InvalidArgumentException('Archive missing required keys: events, chain, manifest');
        }

        /** @var list<array<string, mixed>> $events */
        $events = $data['events'];

        /** @var list<array<string, mixed>> $chain */
        $chain = $data['chain'];

        /** @var array<string, mixed> $manifest */
        $manifest = $data['manifest'];

        return new self(
            events: $events,
            chainLinks: $chain,
            manifest: $manifest,
            mac: isset($data['mac']) && is_string($data['mac']) ? $data['mac'] : null,
        );
    }
}
