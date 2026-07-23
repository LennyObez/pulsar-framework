<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_string;

/**
 * PSD2 certificate validation configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CertificateConfig
{
    /**
     * @param list<string> $trustedIssuers List of trusted CA distinguished names
     * @param string|null $trustedCaBundlePath Path to a PEM bundle of trusted eIDAS QTSP
     *        CA certificates. Chain verification anchors to this; when null the default
     *        validator FAILS CLOSED (it refuses to trust any certificate) rather than
     *        deriving authorization from unverified, attacker-suppliable string fields.
     */
    public function __construct(
        public bool $requireQualified = true,
        public bool $checkRevocation = true,
        public array $trustedIssuers = [],
        public string $validator = 'default',
        public ?string $trustedCaBundlePath = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $bundle = $data['trusted_ca_bundle_path'] ?? null;

        return new self(
            requireQualified: Coerce::strictBool($data['require_qualified'] ?? null, true),
            checkRevocation: Coerce::strictBool($data['check_revocation'] ?? null, true),
            trustedIssuers: Coerce::listOfString($data['trusted_issuers'] ?? null),
            validator: Coerce::string($data['validator'] ?? null, 'default'),
            trustedCaBundlePath: is_string($bundle) && $bundle !== '' ? $bundle : null,
        );
    }
}
