<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * SEPA Direct Debit configuration for EU payments.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SepaConfig
{
    public function __construct(
        public string $creditorId,
        public string $creditorName,
        public string $creditorIban,
        public string $creditorBic,
        public int $preNotificationDays,
        public bool $enabled,
    ) {}

    /**
     * @param array{
     *     creditor_id?: string,
     *     creditor_name?: string,
     *     creditor_iban?: string,
     *     creditor_bic?: string,
     *     pre_notification_days?: int,
     *     enabled?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            creditorId: $data['creditor_id'] ?? '',
            creditorName: $data['creditor_name'] ?? '',
            creditorIban: $data['creditor_iban'] ?? '',
            creditorBic: $data['creditor_bic'] ?? '',
            preNotificationDays: $data['pre_notification_days'] ?? 14,
            enabled: (bool) ($data['enabled'] ?? false),
        );
    }
}
