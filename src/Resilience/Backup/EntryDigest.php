<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use NoDiscard;
use Pulsar\Api\Internal;
use SodiumException;

use function sodium_crypto_generichash_final;
use function sodium_crypto_generichash_init;
use function sodium_crypto_generichash_update;
use function strlen;

/**
 * Runs a BLAKE2b digest and a byte count over one entry as it streams past.
 *
 * Mutable, and the only mutable object in the archive path. It has to be: the
 * whole design refuses to hold an entry in memory, so the only place its length
 * and its digest can be computed is incrementally, as the chunks go by. Keeping
 * that state in one small named object — rather than in two locals threaded
 * through four methods by reference — is what lets the writer and the reader run
 * the SAME accumulator, which matters because a digest computed one way on the
 * way in and another way on the way out would compare equal only by accident.
 */
#[Internal(reason: 'Streaming accumulator used by SealedArchiveBackupService on both sides of the round trip')]
final class EntryDigest
{
    /**
     * The running BLAKE2b state. Read through {@see openState()}, never directly.
     */
    private string $state;

    /** @var int<0, max> */
    private int $bytes = 0;

    private ?string $digest = null;

    private ?string $recordedDigest = null;

    private ?int $recordedBytes = null;

    /**
     * @throws SodiumException
     */
    public function __construct()
    {
        $this->state = sodium_crypto_generichash_init('', ArchiveFormat::DIGEST_BYTES);
    }

    /**
     * @throws SodiumException
     */
    public function add(string $chunk): void
    {
        $state = $this->openState();

        sodium_crypto_generichash_update($state, $chunk);

        $this->state = $state;
        $this->bytes += strlen($chunk);
    }

    /**
     * Close the digest with what the archive recorded for this entry.
     *
     * The recorded values are kept BESIDE the computed ones rather than compared
     * here, so the caller decides what a mismatch means: on the way in there is
     * nothing recorded to compare with, and on the way out a mismatch is a refusal
     * that has to name the entry.
     *
     * @throws SodiumException
     */
    public function close(?string $recordedDigest = null, ?int $recordedBytes = null): void
    {
        if ($this->digest === null) {
            // Through a local because final() takes the state BY REFERENCE and
            // consumes it. The copy is what gets consumed, so this object is left
            // holding a state it will never use again rather than a freed one.
            $state = $this->openState();

            $this->digest = sodium_crypto_generichash_final($state, ArchiveFormat::DIGEST_BYTES);
        }

        // Only what the caller actually recorded is written. A later bare close() --
        // digest() performs one -- must not erase the values the archive carried,
        // because assertEntryIntact() would then read them as "this entry has no end
        // marker" and refuse an archive that is intact.
        if ($recordedDigest !== null) {
            $this->recordedDigest = $recordedDigest;
        }

        if ($recordedBytes !== null) {
            $this->recordedBytes = $recordedBytes;
        }
    }

    #[NoDiscard]
    public function closed(): bool
    {
        return $this->digest !== null;
    }

    /**
     * @return non-empty-string
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function digest(): string
    {
        $this->close();

        $digest = $this->digest;

        if ($digest === null || $digest === '') {
            throw new SodiumException('The digest closed without producing one.');
        }

        return $digest;
    }

    /**
     * The hash state, as the thing libsodium will accept.
     *
     * The state is held as a plain `string` because the two analysers this project
     * runs disagree about what `sodium_crypto_generichash_init()` returns, and a
     * property typed for one of them is a lie to the other. It is narrowed HERE
     * instead, once, at the only place the state is read. An empty state means the
     * crypto runtime handed back something libsodium's own update and final calls
     * refuse, and continuing would produce a digest computed over nothing -- which
     * is precisely the failure a per-entry digest exists to catch, so it refuses
     * rather than returning one.
     *
     * @return non-empty-string
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    private function openState(): string
    {
        $state = $this->state;

        if ($state === '') {
            throw new SodiumException('The BLAKE2b state is empty, so no digest can be computed over it.');
        }

        return $state;
    }

    /**
     * @return int<0, max>
     */
    #[NoDiscard]
    public function bytes(): int
    {
        return $this->bytes;
    }

    #[NoDiscard]
    public function recordedDigest(): ?string
    {
        return $this->recordedDigest;
    }

    #[NoDiscard]
    public function recordedBytes(): ?int
    {
        return $this->recordedBytes;
    }
}
