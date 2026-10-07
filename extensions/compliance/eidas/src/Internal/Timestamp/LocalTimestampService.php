<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Internal\Timestamp;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Eidas\Contracts\TimestampServiceInterface;
use Pulsar\Extension\Eidas\Domain\TimestampToken;

use function bin2hex;
use function hash;
use function hash_equals;
use function random_bytes;

/**
 * Local timestamp service for development and testing.
 *
 * Production implementations should integrate with an RFC 3161
 * Time-Stamp Authority (TSA) for qualified timestamps.
 */
#[Internal(reason: 'Use TimestampServiceInterface with a real TSA for production')]
final readonly class LocalTimestampService implements TimestampServiceInterface
{
    public function __construct(
        private string $tsaName = 'Pulsar Local TSA',
    ) {}

    #[Override]
    public function timestamp(string $data, string $hashAlgorithm = 'sha256'): TimestampToken
    {
        $dataHash = hash($hashAlgorithm, $data);
        $now = new DateTimeImmutable();
        $tokenId = bin2hex(random_bytes(16));

        // The encoded token binds the hash to the timestamp
        $encodedToken = hash_hmac('sha256', $dataHash . '|' . $now->format('U.u'), $tokenId);

        return new TimestampToken(
            tokenId: $tokenId,
            dataHash: $dataHash,
            hashAlgorithm: $hashAlgorithm,
            timestamp: $now,
            tsaName: $this->tsaName,
            isQualified: false,
            encodedToken: $encodedToken,
        );
    }

    #[Override]
    public function verifyTimestamp(string $data, TimestampToken $token): bool
    {
        $dataHash = hash($token->hashAlgorithm, $data);

        if (!hash_equals($token->dataHash, $dataHash)) {
            return false;
        }

        // Verify the token binding
        $expectedToken = hash_hmac(
            'sha256',
            $dataHash . '|' . $token->timestamp->format('U.u'),
            $token->tokenId,
        );

        return hash_equals($token->encodedToken, $expectedToken);
    }
}
