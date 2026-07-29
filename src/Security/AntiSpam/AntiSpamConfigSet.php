<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Internal;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerVerificationConfig;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivacyPassConfig;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskConfig;
use Pulsar\Security\AntiSpam\Risk\DatacenterIpConfig;
use Pulsar\Security\AntiSpam\Risk\Ja4Config;
use Pulsar\Security\AntiSpam\Risk\VelocityConfig;

use function array_diff;
use function array_intersect;
use function array_values;
use function is_array;

/**
 * The parsed config/anti-spam.php as one typed object.
 *
 * anti-spam.php is a single config file with several typed sub-sections — the
 * top-level anti-spam settings plus adaptive_risk, ja4, velocity, datacenter,
 * privacy_pass, ai_crawler_verification, ai_crawlers, and the email-domain
 * checks. This set is the DTO the config loader builds into the ConfigRepository,
 * so the whole file flows through the single config-load path (overrides,
 * fail-closed on a malformed file, strict-key checking) instead of nine ad-hoc
 * re-reads; {@see \Pulsar\Core\Wiring\AntiSpamWiring} distributes the parts into
 * the container, its consumer-facing interface, unchanged.
 *
 * Unknown-key reporting composes the two top-level DTOs that read anti-spam.php
 * (AntiSpamConfig and EmailDomainCheckConfig) and excludes the typed sub-section
 * containers, so the central sweep flags a genuine typo — in any top-level key or
 * a misspelled sub-section name — under the real file label "anti-spam", without
 * false-positives on the file's legitimate keys. See {@see self::unknownConfigKeys()}.
 */
#[Internal]
final readonly class AntiSpamConfigSet implements ReportsUnknownKeys
{
    /**
     * Typed sub-section containers of anti-spam.php, each consumed by its own
     * config below. Keys nested here are NOT unknown; a MISSPELLED container
     * name (not in this list and read by no top-level DTO) still surfaces.
     *
     * @var list<string>
     */
    private const array SUBSECTION_KEYS = [
        'adaptive_risk', 'ja4', 'velocity', 'datacenter', 'privacy_pass',
        'ai_crawler_verification', 'ai_crawlers',
    ];

    public function __construct(
        public AntiSpamConfig $antiSpam,
        public EmailDomainCheckConfig $emailDomain,
        public AdaptiveRiskConfig $adaptiveRisk,
        public Ja4Config $ja4,
        public VelocityConfig $velocity,
        public DatacenterIpConfig $datacenter,
        public PrivacyPassConfig $privacyPass,
        public AiCrawlerVerificationConfig $aiCrawlerVerification,
        public AiCrawlerConfig $aiCrawler,
    ) {}

    /**
     * @param array<string, mixed> $data Raw config/anti-spam.php array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            antiSpam: AntiSpamConfig::fromArray($data),
            emailDomain: EmailDomainCheckConfig::fromArray($data),
            adaptiveRisk: AdaptiveRiskConfig::fromArray(self::section($data, 'adaptive_risk')),
            ja4: Ja4Config::fromArray(self::section($data, 'ja4')),
            velocity: VelocityConfig::fromArray(self::section($data, 'velocity')),
            datacenter: DatacenterIpConfig::fromArray(self::section($data, 'datacenter')),
            privacyPass: PrivacyPassConfig::fromArray(self::section($data, 'privacy_pass')),
            aiCrawlerVerification: AiCrawlerVerificationConfig::fromArray(self::section($data, 'ai_crawler_verification')),
            aiCrawler: AiCrawlerConfig::fromArray(self::section($data, 'ai_crawlers')),
        );
    }

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        // anti-spam.php is read by two top-level DTOs (AntiSpamConfig and
        // EmailDomainCheckConfig) plus the typed sub-sections. A top-level key is
        // genuinely unknown only when NEITHER top-level DTO reads it AND it is not
        // a sub-section container. The intersection of the two reporters' unknown
        // lists, minus the sub-section names, is exactly that set — so a real typo
        // (including a misspelled sub-section name) still surfaces, while every
        // legitimate sibling key is ignored. Sub-section-INTERNAL typos are not
        // reported here (the sub-configs do not report), matching prior behaviour.
        $unknownToBothTopLevel = array_intersect(
            $this->antiSpam->unknownConfigKeys(),
            $this->emailDomain->unknownConfigKeys(),
        );

        return array_values(array_diff($unknownToBothTopLevel, self::SUBSECTION_KEYS));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function section(array $data, string $key): array
    {
        $section = $data[$key] ?? null;

        if (!is_array($section)) {
            return [];
        }

        /** @var array<string, mixed> $section */
        return $section;
    }
}
