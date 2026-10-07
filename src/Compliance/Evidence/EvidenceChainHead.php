<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use DateTimeImmutable;
use Exception;
use NoDiscard;
use Pulsar\Api\Api;

use function implode;
use function is_int;
use function is_string;
use function strlen;

/**
 * The authenticated statement of how tall an evidence chain is.
 *
 * A hash chain proves that no link still PRESENT has moved. It says nothing
 * about links removed from the end: drop the last two records of a linked list
 * and what remains is a shorter, perfectly self-consistent linked list. The
 * missing records are missing from the proof as well as from the file, and no
 * amount of re-hashing the survivors brings them back.
 *
 * The head is the fact that closes that hole. It is written after every append
 * and it says, under the same key the records are signed with, "this chain has
 * written {@see $height} records and the last one's signature is
 * {@see $signature}". A verifier that finds fewer records than the head attests
 * has found a truncation; one that finds a record at a position the head does
 * not cover has found an append the anchor has not caught up with. Neither can
 * be produced by editing the register alone, because the head carries its own
 * {@see $mac} over every field including {@see $genesis} — a forger would need
 * the key.
 *
 * WHAT THE HEAD DOES NOT PROVE, stated here rather than discovered later: it is
 * an anchor on the same host as the register. Someone who can replace the
 * register AND its head with an older, genuine pair rolls the chain back to that
 * pair, and nothing inside this process can tell that from a chain which simply
 * has not run since. Defeating a rollback needs an anchor the host cannot
 * rewrite — a write-once medium, an external notary, or shipping each head
 * off-box as it is written — and that is an operator control, not something this
 * class can claim.
 *
 * @see EvidenceChainHeadAware for how a store persists it
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class EvidenceChainHead
{
    /**
     * @param int    $version   Layout of the signed message, so a verifier that meets
     *                          a head it does not implement refuses rather than guesses
     * @param string $genesis   The chain's genesis commitment: which key this head belongs to
     * @param int    $height    How many records the chain has written
     * @param string $signature Signature of the record at position `$height - 1`, or the
     *                          genesis signature when `$height` is 0
     * @param string $mac       HMAC over {@see message()} under the evidence key
     */
    public function __construct(
        public int $version,
        public string $genesis,
        public int $height,
        public string $signature,
        public DateTimeImmutable $updatedAt,
        public string $mac,
    ) {}

    /**
     * The message the {@see $mac} covers.
     *
     * Length-prefixed per field and newline-joined, the encoding
     * {@see \Pulsar\Security\Audit\AuditEntry} uses and for the same reason: a
     * plain concatenation lets two different heads share a message when one
     * field's tail can be read as the next field's head.
     *
     * The timestamp is ATOM because that is what {@see FileEvidenceStore} writes
     * and reads back; signing a microsecond rendering would produce a head that
     * stops authenticating the moment it is reloaded.
     */
    #[NoDiscard]
    public function message(): string
    {
        $parts = [];

        foreach ([
            (string) $this->version,
            $this->genesis,
            (string) $this->height,
            $this->signature,
            $this->updatedAt->format(DateTimeImmutable::ATOM),
        ] as $field) {
            $parts[] = strlen($field) . ':' . $field;
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'genesis' => $this->genesis,
            'height' => $this->height,
            'signature' => $this->signature,
            'updated_at' => $this->updatedAt->format(DateTimeImmutable::ATOM),
            'mac' => $this->mac,
        ];
    }

    /**
     * Rebuild a head from a decoded anchor file, or null when the shape is not
     * one this class wrote.
     *
     * Null means "the anchor could not be read", which a verifier reports as a
     * finding in its own right — an anchor that is present and unreadable is not
     * the same event as one that was never written, and neither is the same as
     * one that reads cleanly and fails its MAC.
     *
     * @param array<string, mixed> $decoded
     */
    #[NoDiscard]
    public static function fromArray(array $decoded): ?self
    {
        /** @var mixed $version */
        $version = $decoded['version'] ?? null;
        /** @var mixed $genesis */
        $genesis = $decoded['genesis'] ?? null;
        /** @var mixed $height */
        $height = $decoded['height'] ?? null;
        /** @var mixed $signature */
        $signature = $decoded['signature'] ?? null;
        /** @var mixed $updatedAt */
        $updatedAt = $decoded['updated_at'] ?? null;
        /** @var mixed $mac */
        $mac = $decoded['mac'] ?? null;

        if (!is_int($version) || !is_int($height) || $height < 0) {
            return null;
        }

        if (!is_string($genesis) || !is_string($signature) || !is_string($mac) || !is_string($updatedAt)) {
            return null;
        }

        try {
            $timestamp = new DateTimeImmutable($updatedAt);
        } catch (Exception) {
            return null;
        }

        return new self(
            version: $version,
            genesis: $genesis,
            height: $height,
            signature: $signature,
            updatedAt: $timestamp,
            mac: $mac,
        );
    }
}
