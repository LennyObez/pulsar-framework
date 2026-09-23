<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use Pulsar\Api\Api;

/**
 * What an archive says about one entry it holds: its name, its length and the
 * digest recorded over its content when it was written.
 *
 * The digest is NOT the archive's integrity mechanism — the AEAD stream is, and
 * it covers every byte including these records. This exists one level in, and it
 * catches a different failure: a source that yielded a short read, a target that
 * wrote fewer bytes than it consumed, an entry that was reassembled out of order
 * by a future reader. Those are producer and consumer bugs, they leave the seal
 * perfectly intact, and without a per-entry digest a restore would put a
 * truncated table back and report success.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ArchivedEntry
{
    /**
     * @param non-empty-string $name      Relative path inside the archive
     * @param int<0, max>      $bytes     Content length as written
     * @param non-empty-string $digestHex 32-byte BLAKE2b digest of the content, hex-encoded
     */
    public function __construct(
        public string $name,
        public int $bytes,
        public string $digestHex,
    ) {}
}
