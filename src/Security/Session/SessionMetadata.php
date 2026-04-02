<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use NoDiscard;
use Pulsar\Api\Api;

use function time;

/**
 * Immutable session metadata tracked alongside session data.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SessionMetadata
{
    public function __construct(
        public int $createdAt,
        public int $lastActivity,
        public string $ipAddress,
        public string $userAgent,
        public ?string $userId = null,
        public ?string $fingerprint = null,
    ) {}

    /**
     * @param array{
     *     created_at?: int,
     *     last_activity?: int,
     *     ip_address?: string,
     *     user_agent?: string,
     *     user_id?: string|null,
     *     fingerprint?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            createdAt: $data['created_at'] ?? time(),
            lastActivity: $data['last_activity'] ?? time(),
            ipAddress: $data['ip_address'] ?? '',
            userAgent: $data['user_agent'] ?? '',
            userId: $data['user_id'] ?? null,
            fingerprint: $data['fingerprint'] ?? null,
        );
    }

    /**
     * @return array{created_at: int, last_activity: int, ip_address: string, user_agent: string, user_id: ?string, fingerprint: ?string}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'last_activity' => $this->lastActivity,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'user_id' => $this->userId,
            'fingerprint' => $this->fingerprint,
        ];
    }

    #[NoDiscard]
    public function withLastActivity(int $timestamp): self
    {
        return new self(
            createdAt: $this->createdAt,
            lastActivity: $timestamp,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            userId: $this->userId,
            fingerprint: $this->fingerprint,
        );
    }

    #[NoDiscard]
    public function withUserId(?string $userId): self
    {
        return new self(
            createdAt: $this->createdAt,
            lastActivity: $this->lastActivity,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            userId: $userId,
            fingerprint: $this->fingerprint,
        );
    }

    #[NoDiscard]
    public function withFingerprint(?string $fingerprint): self
    {
        return new self(
            createdAt: $this->createdAt,
            lastActivity: $this->lastActivity,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            userId: $this->userId,
            fingerprint: $fingerprint,
        );
    }
}
