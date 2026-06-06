<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use LogicException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function substr;

/**
 * Pseudonymization service backed by keyed BLAKE2b hashing.
 *
 * Derives a purpose-specific subkey from the application MasterKey and
 * combines it with a per-subject random salt to produce deterministic
 * pseudonyms. The salt is encrypted at rest via EncryptorInterface to
 * prevent offline re-identification.
 *
 * This implementation supports controls for GDPR Article 4(5)
 * pseudonymization and HIPAA Safe Harbor de-identification.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Pseudonymization service implementation')]
final class PseudonymizationService implements PseudonymizationServiceInterface
{
    /**
     * Sub-key ID for pseudonymization key derivation.
     */
    private const int SUB_KEY_ID = 3;

    /**
     * KDF context for pseudonymization (exactly 8 bytes).
     */
    private const string CONTEXT = 'pseudo__';

    /**
     * Length of the random salt in bytes.
     */
    private const int SALT_LENGTH = 16;

    /**
     * Number of hex characters to use from the HMAC output.
     */
    private const int PSEUDONYM_HEX_LENGTH = 32;

    private ?string $derivedKey;

    public function __construct(
        MasterKey $masterKey,
        private readonly PseudonymLookupInterface $lookup,
        private readonly EncryptorInterface $encryptor,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
        $this->derivedKey = $masterKey->deriveSubKey(self::SUB_KEY_ID, self::CONTEXT);
    }

    public function __destruct()
    {
        if ($this->derivedKey !== null) {
            sodium_memzero($this->derivedKey);
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'derivedKey' => '[REDACTED]',
            'lookup' => $this->lookup::class,
            'encryptor' => $this->encryptor::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('PseudonymizationService must not be serialized: derived key material would leak.');
    }

    #[Override]
    public function pseudonymize(string $subjectId): string
    {
        $existing = $this->lookup->findBySubjectId($subjectId);

        if ($existing !== null) {
            return $existing->pseudonym;
        }

        if ($this->derivedKey === null) {
            throw new LogicException('PseudonymizationService has been destroyed and cannot pseudonymize.');
        }

        $salt = random_bytes(self::SALT_LENGTH);

        $fullHex = Hmac::computeHex($subjectId . $salt, $this->derivedKey);
        $pseudonym = substr($fullHex, 0, self::PSEUDONYM_HEX_LENGTH);

        $encryptedSalt = $this->encryptor->encrypt($salt);

        $this->lookup->store($subjectId, $pseudonym, $encryptedSalt);

        return $pseudonym;
    }

    #[Override]
    public function resolve(string $pseudonym): ?string
    {
        $mapping = $this->lookup->findByPseudonym($pseudonym);

        if ($mapping === null) {
            return null;
        }

        $this->auditLogger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: AuditActor::system('compliance.pseudonymization'),
            action: 'pseudonym.resolve',
            resource: $pseudonym,
        );

        return $mapping->subjectId;
    }

    #[Override]
    public function exists(string $subjectId): bool
    {
        return $this->lookup->findBySubjectId($subjectId) !== null;
    }
}
