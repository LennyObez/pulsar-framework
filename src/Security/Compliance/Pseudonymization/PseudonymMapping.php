<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use DateTimeZone;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable record representing a pseudonym-to-subject mapping.
 *
 * Stores the relationship between a real subject identifier and its
 * pseudonym, along with the encrypted salt used during derivation.
 * This mapping supports controls for GDPR Article 4(5) pseudonymization
 * requirements.
 */
#[Api(since: '1.0.0')]
final readonly class PseudonymMapping
{
    public function __construct(
        public string $subjectId,
        public string $pseudonym,
        public string $encryptedSalt,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * @return array<string, string>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'subject_id' => $this->subjectId,
            'pseudonym' => $this->pseudonym,
            'encrypted_salt' => $this->encryptedSalt,
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * Reconstruct a mapping from its array representation.
     *
     * @param array<string, string> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            subjectId: $data['subject_id'],
            pseudonym: $data['pseudonym'],
            encryptedSalt: $data['encrypted_salt'],
            createdAt: new DateTimeImmutable($data['created_at'], new DateTimeZone('UTC')),
        );
    }
}
