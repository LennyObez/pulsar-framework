<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymMapping;

#[CoversClass(PseudonymMapping::class)]
final class PseudonymMappingTest extends TestCase
{
    #[Test]
    public function constructionSetsAllProperties(): void
    {
        $createdAt = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00', new DateTimeZone('UTC'));

        $mapping = new PseudonymMapping(
            subjectId: 'user-123',
            pseudonym: 'abcdef1234567890abcdef1234567890',
            encryptedSalt: 'encrypted-salt-data',
            createdAt: $createdAt,
        );

        self::assertSame('user-123', $mapping->subjectId);
        self::assertSame('abcdef1234567890abcdef1234567890', $mapping->pseudonym);
        self::assertSame('encrypted-salt-data', $mapping->encryptedSalt);
        self::assertSame($createdAt, $mapping->createdAt);
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $createdAt = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00', new DateTimeZone('UTC'));

        $mapping = new PseudonymMapping(
            subjectId: 'user-456',
            pseudonym: 'deadbeef12345678deadbeef12345678',
            encryptedSalt: 'enc-salt',
            createdAt: $createdAt,
        );

        $array = $mapping->toArray();

        self::assertSame('user-456', $array['subject_id']);
        self::assertSame('deadbeef12345678deadbeef12345678', $array['pseudonym']);
        self::assertSame('enc-salt', $array['encrypted_salt']);
        self::assertSame('2025-06-15T10:30:00.000000+00:00', $array['created_at']);
    }

    #[Test]
    public function fromArrayReconstructsMapping(): void
    {
        $data = [
            'subject_id' => 'user-789',
            'pseudonym' => 'cafebabe12345678cafebabe12345678',
            'encrypted_salt' => 'some-encrypted-salt',
            'created_at' => '2025-06-15T10:30:00.000000+00:00',
        ];

        $mapping = PseudonymMapping::fromArray($data);

        self::assertSame('user-789', $mapping->subjectId);
        self::assertSame('cafebabe12345678cafebabe12345678', $mapping->pseudonym);
        self::assertSame('some-encrypted-salt', $mapping->encryptedSalt);
        self::assertSame('2025-06-15T10:30:00.000000+00:00', $mapping->createdAt->format('Y-m-d\TH:i:s.uP'));
    }

    #[Test]
    public function fromArrayToArrayRoundtrip(): void
    {
        $original = new PseudonymMapping(
            subjectId: 'roundtrip-subject',
            pseudonym: '1111222233334444aaaabbbbccccdddd',
            encryptedSalt: 'roundtrip-salt',
            createdAt: new DateTimeImmutable('2025-01-01T00:00:00.000000+00:00', new DateTimeZone('UTC')),
        );

        $reconstructed = PseudonymMapping::fromArray($original->toArray());

        self::assertSame($original->subjectId, $reconstructed->subjectId);
        self::assertSame($original->pseudonym, $reconstructed->pseudonym);
        self::assertSame($original->encryptedSalt, $reconstructed->encryptedSalt);
        self::assertSame(
            $original->createdAt->format('Y-m-d\TH:i:s.uP'),
            $reconstructed->createdAt->format('Y-m-d\TH:i:s.uP'),
        );
    }
}
