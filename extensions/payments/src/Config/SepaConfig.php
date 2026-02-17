<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_string;

/**
 * SEPA Direct Debit configuration for EU payments.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $creditorIdVal = $data['creditor_id'] ?? null;
        $creditorId = is_string($creditorIdVal) ? $creditorIdVal : '';
        $creditorNameVal = $data['creditor_name'] ?? null;
        $creditorName = is_string($creditorNameVal) ? $creditorNameVal : '';
        $creditorIbanVal = $data['creditor_iban'] ?? null;
        $creditorIban = is_string($creditorIbanVal) ? $creditorIbanVal : '';
        $creditorBicVal = $data['creditor_bic'] ?? null;
        $creditorBic = is_string($creditorBicVal) ? $creditorBicVal : '';
        $preNotifVal = $data['pre_notification_days'] ?? null;
        $preNotificationDays = is_int($preNotifVal) ? $preNotifVal : 14;
        $enabled = (bool) ($data['enabled'] ?? false);

        return new self(
            creditorId: $creditorId,
            creditorName: $creditorName,
            creditorIban: $creditorIban,
            creditorBic: $creditorBic,
            preNotificationDays: $preNotificationDays,
            enabled: $enabled,
        );
    }
}
