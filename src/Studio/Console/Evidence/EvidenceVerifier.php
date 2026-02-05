<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Evidence;

use function count;
use function hash;
use function hash_equals;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Hmac;
use SodiumException;

/**
 * Verifies the integrity of an evidence chain.
 *
 * Supports three verification modes:
 * - Public: SHA-256 chain integrity (no keys needed)
 * - Tamper-evident: chain + per-link BLAKE2b MAC (requires chain MAC key)
 * - Full: chain + MAC + archive MAC (requires all keys)
 *
 * Handles both full-from-seed and window verification (after retention pruning).
 */
#[Internal]
final class EvidenceVerifier
{
    /**
     * Verify an evidence archive or chain links.
     *
     * @param list<array<string, mixed>> $chainLinks Chain links with event data joined
     * @param ?string $chainMacKey For tamper-evident mode
     * @param int $linksPruned From studio_meta counter, for reporting
     * @return array{
     *     mode: string,
     *     anchor_type: string,
     *     earliest_event_id: ?string,
     *     latest_event_id: ?string,
     *     links_verified: int,
     *     links_pruned: int,
     *     chain_intact: bool,
     *     mac_verified: ?bool,
     *     failures: list<array{index: int, event_id: string, reason: string}>
     * }
     * @throws SodiumException If MAC verification fails due to sodium error
     */
    public function verify(
        array $chainLinks,
        ?string $chainMacKey = null,
        int $linksPruned = 0,
    ): array {
        $failures = [];
        $linksVerified = 0;
        $macVerified = null;

        if ($chainLinks === []) {
            return [
                'mode' => 'empty',
                'anchor_type' => 'none',
                'earliest_event_id' => null,
                'latest_event_id' => null,
                'links_verified' => 0,
                'links_pruned' => $linksPruned,
                'chain_intact' => true,
                'mac_verified' => null,
                'failures' => [],
            ];
        }

        $firstLink = $chainLinks[0];
        $seedHash = HashChain::seedHash();
        /** @var string $firstPreviousHash */
        $firstPreviousHash = $firstLink['previous_hash'] ?? '';
        $isFullFromSeed = hash_equals($seedHash, $firstPreviousHash);
        $mode = $isFullFromSeed ? 'full' : 'window';
        $anchorType = $isFullFromSeed ? 'seed' : 'window_boundary';

        $previousHash = $firstPreviousHash;

        if ($chainMacKey !== null) {
            $macVerified = true;
        }

        foreach ($chainLinks as $index => $link) {
            /** @var string $eventId */
            $eventId = $link['event_id'] ?? '';
            /** @var string $expectedHash */
            $expectedHash = $link['current_hash'] ?? '';

            $canonical = $this->buildCanonical($link);

            $computedHash = hash('sha256', $previousHash . '|' . $canonical);

            if (!hash_equals($expectedHash, $computedHash)) {
                $failures[] = [
                    'index' => $index,
                    'event_id' => $eventId,
                    'reason' => 'hash mismatch',
                ];
            }

            if ($chainMacKey !== null && isset($link['link_mac']) && is_string($link['link_mac'])) {
                if (!Hmac::verifyHex($expectedHash, $link['link_mac'], $chainMacKey)) {
                    $failures[] = [
                        'index' => $index,
                        'event_id' => $eventId,
                        'reason' => 'mac mismatch',
                    ];
                    $macVerified = false;
                }
            }

            $previousHash = $expectedHash;
            $linksVerified++;
        }

        $lastLink = $chainLinks[count($chainLinks) - 1];

        /** @var string $firstEventId */
        $firstEventId = $firstLink['event_id'] ?? '';
        /** @var string $lastEventId */
        $lastEventId = $lastLink['event_id'] ?? '';

        return [
            'mode' => $mode,
            'anchor_type' => $anchorType,
            'earliest_event_id' => $firstEventId,
            'latest_event_id' => $lastEventId,
            'links_verified' => $linksVerified,
            'links_pruned' => $linksPruned,
            'chain_intact' => $failures === [],
            'mac_verified' => $macVerified,
            'failures' => $failures,
        ];
    }

    /**
     * Verify an archive's MAC.
     *
     * @throws JsonException If JSON encoding fails
     */
    public function verifyArchiveMac(EvidenceArchive $archive, string $archiveMacKey): bool
    {
        if ($archive->mac === null) {
            return false;
        }

        $manifestJson = json_encode($archive->manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $archiveDigest = hash('sha256', $manifestJson);

        return Hmac::verifyHex($archiveDigest, $archive->mac, $archiveMacKey);
    }

    /**
     * Build the canonical string from a chain link's event data.
     *
     * @param array<string, mixed> $link
     */
    private function buildCanonical(array $link): string
    {
        /** @var string $eventId */
        $eventId = $link['event_id'] ?? '';
        /** @var string $eventType */
        $eventType = $link['event_type'] ?? '';
        /** @var string $schemaVersion */
        $schemaVersion = $link['schema_version'] ?? '';
        /** @var string $timestampUs */
        $timestampUs = $link['timestamp_us'] ?? '';
        /** @var string $traceId */
        $traceId = $link['trace_id'] ?? '';
        /** @var string $payloadHash */
        $payloadHash = $link['payload_hash'] ?? '';

        return $eventId . '|' . $eventType . '|' . $schemaVersion . '|' . $timestampUs . '|' . $traceId . '|' . $payloadHash;
    }
}
