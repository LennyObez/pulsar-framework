<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use NoDiscard;
use Pulsar\Api\Internal;
use SensitiveParameter;
use SodiumException;

use function fwrite;
use function is_string;
use function sodium_crypto_secretstream_xchacha20poly1305_init_push;
use function sodium_crypto_secretstream_xchacha20poly1305_push;
use function strlen;
use function substr;

use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

/**
 * Seals a byte stream into an archive, one bounded chunk at a time.
 *
 * `crypto_secretstream_xchacha20poly1305` rather than a single AEAD over the
 * whole file, and the difference is the reason a backup can be taken at all:
 *
 *  - A single AEAD cannot be verified until the last byte has been read, which
 *    means an implementation either buffers the whole archive or hands the caller
 *    unauthenticated plaintext and hopes. This one authenticates every chunk
 *    before that chunk's plaintext is returned, so a restore never writes a byte
 *    it has not already authenticated.
 *  - The stream is ORDERED. Reordering or dropping a chunk breaks the next pull,
 *    so an attacker cannot rearrange an archive out of its own authentic pieces.
 *  - The last chunk carries a FINAL tag. A truncated archive is therefore
 *    detectable as truncated, which a chain of independently-sealed blocks is not.
 *
 * The plaintext header travels as the additional data of every push, binding the
 * whole stream to the creation instant and key id an assessor reads.
 *
 * MEMORY. One chunk of {@see ArchiveFormat::CHUNK_SIZE} plus whatever the caller
 * has appended since the last flush — {@see append()} drains the buffer to below
 * the chunk size on every call, so the bound holds however large the values
 * handed to it are.
 */
#[Internal(reason: 'The sealing half of the archive format; reached through SealedArchiveBackupService')]
final class SealedArchiveWriter
{
    private string $state;

    private string $buffer = '';

    /** @var int<0, max> */
    private int $sealedBytes = 0;

    private bool $finished = false;

    /**
     * @param resource $handle Open for writing, positioned after the plaintext header
     * @param string   $key    The archive key
     * @param string   $aad    The plaintext header, authenticated with every chunk
     *
     * @throws BackupException when the stream header cannot be written
     * @throws SodiumException
     */
    public function __construct(
        private $handle,
        #[SensitiveParameter]
        string $key,
        private readonly string $aad,
        private readonly string $path,
    ) {
        // libsodium hands back a two-element array and the analyser has no shape for
        // it, so the pair is checked rather than destructured on faith. Sealing with
        // something that is not a state, or writing something that is not a stream
        // header, would produce a file that looks like an archive and opens as
        // nothing — which is the artefact this whole class exists not to leave behind.
        $opened = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $state = $opened[0] ?? null;
        $streamHeader = $opened[1] ?? null;

        if (!is_string($state) || !is_string($streamHeader) || $streamHeader === '') {
            throw BackupException::destinationUnwritable(
                $path,
                'libsodium did not open a sealing stream, so nothing can be sealed into this archive',
            );
        }

        $this->state = $state;

        $this->put($streamHeader);
    }

    /**
     * @throws BackupException
     * @throws SodiumException
     */
    public function append(string $bytes): void
    {
        $this->buffer .= $bytes;

        while (strlen($this->buffer) >= ArchiveFormat::CHUNK_SIZE) {
            $chunk = substr($this->buffer, 0, ArchiveFormat::CHUNK_SIZE);
            $this->buffer = substr($this->buffer, ArchiveFormat::CHUNK_SIZE);

            $this->seal($chunk, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
        }
    }

    /**
     * Seal whatever is left with the final tag, which is what makes a truncated
     * archive detectable rather than merely shorter.
     *
     * @throws BackupException
     * @throws SodiumException
     */
    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->seal($this->buffer, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        $this->buffer = '';
        $this->finished = true;
    }

    /**
     * Bytes actually written to the destination, seal and framing included.
     *
     * @return int<0, max>
     */
    #[NoDiscard]
    public function sealedBytes(): int
    {
        return $this->sealedBytes;
    }

    /**
     * @throws BackupException
     * @throws SodiumException
     */
    private function seal(string $plain, int $tag): void
    {
        $sealed = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, $this->aad, $tag);

        $this->put(ArchiveFormat::uint32(strlen($sealed)) . $sealed);
    }

    /**
     * @throws BackupException
     */
    private function put(string $bytes): void
    {
        $offered = strlen($bytes);
        $written = fwrite($this->handle, $bytes);

        if ($written === false || $written !== $offered) {
            throw BackupException::destinationUnwritable(
                $this->path,
                'the destination accepted fewer bytes than were offered, so the archive is incomplete',
            );
        }

        // The offered length, not the returned one: they are equal by the check
        // above, and only strlen() carries the fact that a byte count is not negative.
        $this->sealedBytes += $offered;
    }
}
