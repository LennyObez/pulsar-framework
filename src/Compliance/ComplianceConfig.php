<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;

use function array_merge;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * The parsed config/compliance.php: which regulatory frameworks the deployment
 * must satisfy, and how the compliance verification engine behaves.
 *
 * The enabled frameworks feed {@see ComplianceProfileResolver}, which computes
 * the strictest {@see ComplianceProfile} across them. {@see $strictMode} decides
 * how a non-compliant operator setting is handled at boot: silently tightened to
 * the compliant value with a warning (false, the default), or refused outright
 * (true, fail-closed). See {@see \Pulsar\Core\Wiring\ComplianceWiring}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ComplianceConfig implements ReportsUnknownKeys
{
    /** Top-level keys read from config/compliance.php. */
    private const array KNOWN_KEYS = ['enabled_frameworks', 'verification'];

    /** Keys read from the nested `verification` section. */
    private const array VERIFICATION_KEYS = ['enabled', 'boot_check', 'evidence_interval', 'strict_mode'];

    /**
     * @param list<ComplianceFramework> $enabledFrameworks Frameworks the deployment must satisfy
     * @param bool $verificationEnabled Whether the continuous verification engine runs
     * @param bool $bootCheck Whether controls are verified once at boot
     * @param int $evidenceInterval Seconds between evidence-collection passes
     * @param bool $strictMode Fail-closed at boot on any non-compliant setting (else tighten + warn)
     * @param list<string> $unknownKeys Keys present in the file that this DTO does not read (typos)
     */
    public function __construct(
        public array $enabledFrameworks = [],
        public bool $verificationEnabled = true,
        public bool $bootCheck = true,
        public int $evidenceInterval = 3600,
        public bool $strictMode = false,
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * @param array<string, mixed> $data The raw config/compliance.php array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var mixed $verificationRaw */
        $verificationRaw = $data['verification'] ?? null;
        $verification = is_array($verificationRaw) ? $verificationRaw : [];

        $unknown = array_merge(
            UnknownKeys::collect($data, self::KNOWN_KEYS),
            UnknownKeys::nestedKeys('verification', UnknownKeys::collect($verification, self::VERIFICATION_KEYS)),
        );

        /** @var mixed $enabled */
        $enabled = $verification['enabled'] ?? null;
        /** @var mixed $bootCheck */
        $bootCheck = $verification['boot_check'] ?? null;
        /** @var mixed $evidenceInterval */
        $evidenceInterval = $verification['evidence_interval'] ?? null;
        /** @var mixed $strictMode */
        $strictMode = $verification['strict_mode'] ?? null;

        return new self(
            enabledFrameworks: self::frameworks($data['enabled_frameworks'] ?? null),
            verificationEnabled: is_bool($enabled) ? $enabled : true,
            bootCheck: is_bool($bootCheck) ? $bootCheck : true,
            evidenceInterval: is_int($evidenceInterval) ? $evidenceInterval : 3600,
            strictMode: is_bool($strictMode) ? $strictMode : false,
            unknownKeys: $unknown,
        );
    }

    /**
     * Normalize the enabled_frameworks list. Accepts ComplianceFramework instances
     * (as the shipped config writes them) and their string values (e.g. 'pci_dss'),
     * dropping anything unrecognized and de-duplicating.
     *
     * @return list<ComplianceFramework>
     */
    private static function frameworks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $frameworks = [];

        /** @var mixed $entry */
        foreach ($raw as $entry) {
            $framework = match (true) {
                $entry instanceof ComplianceFramework => $entry,
                is_string($entry) => ComplianceFramework::tryFrom($entry),
                default => null,
            };

            if ($framework !== null && !in_array($framework, $frameworks, true)) {
                $frameworks[] = $framework;
            }
        }

        return $frameworks;
    }
}
