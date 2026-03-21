<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function bin2hex;
use function random_bytes;

/**
 * Immutable entity representing a beta program signup.
 *
 * Tracks user interest in beta releases, including device preference
 * and camera brand compatibility. Supports invitation workflow via
 * hashed invite tokens using clone-with semantics.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BetaSignup
{
    /**
     * @param string $id Hex-encoded random identifier (32 chars)
     * @param string $email Subscriber email address
     * @param DeviceType $deviceType Preferred device platform
     * @param list<string> $cameraBrands Camera brands of interest (stored as JSON)
     * @param DateTimeImmutable $signedUpAt Registration timestamp
     * @param DateTimeImmutable|null $invitedAt Timestamp when invite was sent
     * @param string|null $inviteTokenHash Hash of the invite token
     */
    public function __construct(
        public string $id,
        public string $email,
        public DeviceType $deviceType,
        public array $cameraBrands,
        public DateTimeImmutable $signedUpAt,
        public ?DateTimeImmutable $invitedAt,
        public ?string $inviteTokenHash,
    ) {}

    /**
     * Create a new beta signup with a generated identifier and current timestamp.
     *
     * @param list<string> $cameraBrands
     */
    public static function create(
        string $email,
        DeviceType $deviceType,
        array $cameraBrands = [],
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            email: $email,
            deviceType: $deviceType,
            cameraBrands: $cameraBrands,
            signedUpAt: new DateTimeImmutable(),
            invitedAt: null,
            inviteTokenHash: null,
        );
    }

    /**
     * Record that an invitation has been sent to this signup.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function invite(string $tokenHash): self
    {
        return clone($this, [
            'invitedAt' => new DateTimeImmutable(),
            'inviteTokenHash' => $tokenHash,
        ]);
    }
}
