<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\SubKeyId;
use SodiumException;

use function sodium_bin2hex;
use function sodium_crypto_generichash;
use function sodium_memzero;
use function substr;

use const SODIUM_CRYPTO_GENERICHASH_BYTES_MIN;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;

/**
 * The key a backup archive is sealed with, and the only place it is derived.
 *
 * WHY A BACKUP IS SEALED AT ALL. An archive is a copy of every estate the
 * deployment protects — rows, the audit chain, uploaded files — collapsed into
 * one file whose whole purpose is to leave the host. An unsealed one is not
 * evidence of recovery in this framework any more than an unchained audit log is
 * evidence of activity: anybody who can reach the file can read it, and anybody
 * who can write to it can decide what comes back during the restore that nobody
 * is in a position to double-check. Both halves matter, and the second is the one
 * an operator forgets: a restore is performed under pressure, by someone with
 * privileges, at the moment when a tampered archive would be least likely to be
 * questioned.
 *
 * The key is derived through {@see KeyProviderInterface} — the same seam every
 * other at-rest protection in the framework goes through — under the pair
 * {@see SubKeyId::BackupArchiveSeal} / {@see KDF_CONTEXT}. This class is the pair's
 * declared owner in the registry that `tests/Unit/Security/Crypto/SubKeyIdRegistryTest.php`
 * enforces, and it is the only file in the tree that derives it.
 *
 * THE KEY IS NEVER HELD AS STATE. {@see withArchiveKey()} derives it, hands it to
 * one callable, and zeroes the local copy in a `finally` — so a long-lived
 * service object holding this seal holds a key provider, not key bytes, and a
 * dump of a worker process between backups contains no archive key. That is a
 * deliberately stricter posture than {@see \Pulsar\Security\Crypto\Encryptor},
 * which must keep its key to serve requests; a backup runs for seconds a day and
 * has no reason to.
 *
 * WHAT THIS CLASS DOES NOT DO, stated because the boundary is easy to blur:
 * it does not decide where an archive is stored, does not replicate it off the
 * host, and does not encrypt the storage underneath it. Sealing protects the
 * archive's CONTENT wherever it ends up; the destination, its access control and
 * its offsite copy remain the operator's, and `docs/backup.md` says so in the
 * same table as what the framework ships.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ArchiveSeal
{
    /**
     * The KDF context for the archive key. Exactly eight bytes, as libsodium
     * requires, and used under no other sub-key id in the tree.
     */
    public const string KDF_CONTEXT = 'bkup_arc';

    public function __construct(
        private KeyProviderInterface $keys,
    ) {}

    /**
     * Derive the archive key, run $work with it, and zero it again.
     *
     * @template T
     *
     * @param callable(string): T $work Receives the raw 32-byte key
     *
     * @return T
     *
     * @throws SodiumException when the key hierarchy cannot derive
     */
    public function withArchiveKey(callable $work): mixed
    {
        $key = $this->keys->deriveSubKey(
            SubKeyId::BackupArchiveSeal->value,
            self::KDF_CONTEXT,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
        );

        try {
            return $work($key);
        } finally {
            sodium_memzero($key);
        }
    }

    /**
     * A short, public identifier for the archive key this deployment derives.
     *
     * Written into every archive header in the clear and compared before an
     * archive is opened, so "this was sealed under a key you do not hold" is a
     * named refusal rather than a decryption failure an operator has to interpret
     * during an incident. It is a 64-bit BLAKE2b digest OF THE DERIVED KEY, which
     * is a one-way function of it: publishing it in a header discloses nothing
     * that helps open the archive, exactly as {@see \Pulsar\Security\Crypto\MasterKey::keyId()}
     * publishes one beside every other sealed artefact the framework writes.
     *
     * Computed here rather than taken from `MasterKey::keyId()` because this class
     * holds a {@see KeyProviderInterface}: a deployment may bind a composite or an
     * external provider, and the identifier must be of the key that actually
     * sealed the archive rather than of the one a concrete class would have.
     *
     * @return non-empty-string
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public function keyId(): string
    {
        /** @var non-empty-string */
        return $this->withArchiveKey(static function (string $key): string {
            $digest = sodium_crypto_generichash($key, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MIN);

            return substr(sodium_bin2hex($digest), 0, 16);
        });
    }
}
