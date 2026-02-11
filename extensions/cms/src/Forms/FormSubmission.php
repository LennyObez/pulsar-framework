<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Forms;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function bin2hex;
use function json_encode;
use function random_bytes;
use function sodium_crypto_generichash;

use const JSON_THROW_ON_ERROR;

/**
 * Represents a single form submission with spam scoring and evidence hashing.
 */
#[Api(since: '1.0.0')]
final readonly class FormSubmission
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $id,
        public string $formBlockId,
        public string $contentId,
        public ?string $tenantId,
        public array $data,
        public string $ipHash,
        public string $userAgentHash,
        public DateTimeImmutable $submittedAt,
        public string $evidenceHash,
        public bool $isRead,
        public bool $isSpam,
        public float $spamScore,
        public ?string $spamReason,
    ) {}

    /**
     * Create a new form submission with evidence hash computed from data.
     *
     * @param array<string, mixed> $data
     */
    public static function create(
        string $formBlockId,
        string $contentId,
        array $data,
        string $ipHash,
        string $userAgentHash,
        float $spamScore = 0.0,
        ?string $spamReason = null,
        bool $isSpam = false,
        ?string $tenantId = null,
    ): self {
        $now = new DateTimeImmutable();
        $id = bin2hex(random_bytes(16));

        $serialized = json_encode($data, JSON_THROW_ON_ERROR);
        $evidenceHash = bin2hex(sodium_crypto_generichash($serialized));

        return new self(
            id: $id,
            formBlockId: $formBlockId,
            contentId: $contentId,
            tenantId: $tenantId,
            data: $data,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
            submittedAt: $now,
            evidenceHash: $evidenceHash,
            isRead: false,
            isSpam: $isSpam,
            spamScore: $spamScore,
            spamReason: $spamReason,
        );
    }
}
