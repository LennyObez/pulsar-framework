<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;
use function is_string;

/**
 * DSA extension configuration.
 *
 * Configures the platform type, contact point, and legal representative
 * as required by DSA Articles 11-13. The platform_type determines which
 * obligations apply: intermediary (basic), hosting (+ notice-and-action),
 * platform (+ transparency, trusted flaggers), vlop (+ systemic risk).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DsaConfig
{
    /**
     * @param bool   $enabled             Whether DSA compliance features are active
     * @param string $platformType        One of: intermediary, hosting, platform, vlop
     * @param string $contactPoint        Single point of contact for authorities (Art. 11)
     * @param string $legalRepresentative Legal representative in the EU (Art. 13)
     * @param int    $appealWindowDays    Days users have to submit an appeal (Art. 20)
     * @param int    $noticeResponseHours Maximum hours to acknowledge a notice (Art. 16)
     */
    public function __construct(
        public bool $enabled = false,
        public string $platformType = 'hosting',
        public string $contactPoint = '',
        public string $legalRepresentative = '',
        public int $appealWindowDays = 180,
        public int $noticeResponseHours = 24,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: is_bool($data['enabled'] ?? null) ? $data['enabled'] : false,
            platformType: is_string($data['platform_type'] ?? null) ? $data['platform_type'] : 'hosting',
            contactPoint: is_string($data['contact_point'] ?? null) ? $data['contact_point'] : '',
            legalRepresentative: is_string($data['legal_representative'] ?? null) ? $data['legal_representative'] : '',
            appealWindowDays: is_int($data['appeal_window_days'] ?? null) ? $data['appeal_window_days'] : 180,
            noticeResponseHours: is_int($data['notice_response_hours'] ?? null) ? $data['notice_response_hours'] : 24,
        );
    }

    /**
     * Whether the platform qualifies as a hosting service (Art. 6).
     */
    #[NoDiscard]
    public function isHostingOrAbove(): bool
    {
        return $this->platformType !== 'intermediary';
    }

    /**
     * Whether the platform qualifies as an online platform (Art. 3(i)).
     */
    #[NoDiscard]
    public function isPlatformOrAbove(): bool
    {
        return $this->platformType === 'platform' || $this->platformType === 'vlop';
    }

    /**
     * Whether the platform is a Very Large Online Platform (Art. 33).
     */
    #[NoDiscard]
    public function isVlop(): bool
    {
        return $this->platformType === 'vlop';
    }
}
