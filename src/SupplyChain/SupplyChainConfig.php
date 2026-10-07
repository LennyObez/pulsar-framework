<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\SupplyChain\License\AllowedLicensesConfig;
use Pulsar\SupplyChain\Pipeline\RequiredToolsConfig;
use Pulsar\SupplyChain\Signing\SigningConfig;
use Pulsar\SupplyChain\Vex\VexConfig;

use function is_array;

/**
 * The parsed config/supply-chain.php as one typed object.
 *
 * supply-chain.php is a single config file with four independent concerns —
 * the dependency license allowlist, the required CI pipeline tools, VEX
 * generation, and release-artifact signing. This set is the one DTO the console
 * entrypoint builds from the file, so each supply-chain command receives its
 * typed section instead of re-reading the file (or, as before, silently ignoring
 * it and running on hard-coded defaults). See {@see \Pulsar\SupplyChain\Command}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class SupplyChainConfig
{
    public function __construct(
        public AllowedLicensesConfig $licenses = new AllowedLicensesConfig(),
        public RequiredToolsConfig $pipeline = new RequiredToolsConfig(),
        public VexConfig $vex = new VexConfig(),
        public SigningConfig $signing = new SigningConfig(),
    ) {}

    /**
     * @param array<string, mixed> $data The raw config/supply-chain.php array
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            licenses: AllowedLicensesConfig::fromArray($data),
            pipeline: RequiredToolsConfig::fromArray($data),
            vex: VexConfig::fromArray(self::section($data, 'vex')),
            signing: SigningConfig::fromArray(self::section($data, 'signing')),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function section(array $data, string $key): array
    {
        /** @var mixed $section */
        $section = $data[$key] ?? null;

        if (!is_array($section)) {
            return [];
        }

        /** @var array<string, mixed> $section */
        return $section;
    }
}
