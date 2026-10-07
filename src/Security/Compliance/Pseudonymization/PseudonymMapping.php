<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Immutable record representing a pseudonym-to-subject mapping.
 *
 * Stores the relationship between a real subject identifier and its
 * pseudonym, along with the encrypted salt used during derivation.
 * This mapping supports controls for GDPR Article 4(5) pseudonymization
 * requirements.
 * @api
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
     * @param array{
     *     subject_id?: string|null,
     *     pseudonym?: string|null,
     *     encrypted_salt?: string|null,
     *     created_at?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $subjectId = $data['subject_id'] ?? null;
        $pseudonym = $data['pseudonym'] ?? null;
        $encryptedSalt = $data['encrypted_salt'] ?? null;
        $createdAt = $data['created_at'] ?? null;

        if (!is_string($subjectId) || !is_string($pseudonym) || !is_string($encryptedSalt) || !is_string($createdAt)) {
            throw new InvalidArgumentException(
                'PseudonymMapping::fromArray() requires subject_id, pseudonym, encrypted_salt, and created_at as strings.',
            );
        }

        return new self(
            subjectId: $subjectId,
            pseudonym: $pseudonym,
            encryptedSalt: $encryptedSalt,
            createdAt: new DateTimeImmutable($createdAt, new DateTimeZone('UTC')),
        );
    }
}
