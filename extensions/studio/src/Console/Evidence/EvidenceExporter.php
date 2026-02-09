<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Evidence;

use function count;
use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Extension\Studio\Console\Storage\EncryptedEventStore;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Exception\StudioException;
use SodiumException;

use function time;

/**
 * Exports Studio events and evidence chain as a verifiable archive.
 *
 * Refuses export when encryption-at-rest is enabled but the
 * decryption key is not available — exported archives must contain
 * decrypted plaintext for external auditor verification.
 */
#[Internal]
final readonly class EvidenceExporter
{
    public function __construct(
        private EventStoreInterface $store,
        private ?HmacInterface $hmac = null,
        private ?string $archiveMacKey = null,
        private bool $isEncrypted = false,
        private bool $hasDecryptionKey = true,
    ) {}

    /**
     * Export all events and chain links as an evidence archive.
     *
     * @param array<string, mixed> $filters Optional filters to limit export
     * @throws StudioException If encryption is active but key is missing
     * @throws JsonException If JSON encoding fails
     * @throws SodiumException If HMAC computation fails
     */
    public function export(array $filters = []): EvidenceArchive
    {
        if ($this->isEncrypted && !$this->hasDecryptionKey) {
            throw StudioException::exportRequiresDecryptionKey();
        }

        $events = $this->store->query($filters, limit: PHP_INT_MAX);

        $chainLinks = $this->getChainLinks();

        $manifest = [
            'version' => 1,
            'exported_at' => time(),
            'event_count' => count($events),
            'chain_link_count' => count($chainLinks),
            'filters' => $filters,
        ];

        // Serialize full archive content to bytes for MAC computation
        $archiveContent = json_encode([
            'manifest' => $manifest,
            'events' => $events,
            'chain_links' => $chainLinks,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // MAC over the exact bytes: HMAC-BLAKE2b(SHA-256-hex(bytes), key)
        $mac = null;
        if ($this->archiveMacKey !== null) {
            $archiveDigest = hash('sha256', $archiveContent);
            $mac = $this->hmac?->computeHex($archiveDigest, $this->archiveMacKey);
        }

        return new EvidenceArchive(
            events: $events,
            chainLinks: $chainLinks,
            manifest: $manifest,
            mac: $mac,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getChainLinks(): array
    {
        $store = $this->store;

        if ($store instanceof EncryptedEventStore) {
            $store = $store->inner();
        }

        if ($store instanceof SqliteEventStore) {
            return $store->chainLinks();
        }

        return [];
    }
}
