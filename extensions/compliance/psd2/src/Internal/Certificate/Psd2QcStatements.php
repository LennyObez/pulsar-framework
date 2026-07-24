<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate;

use Pulsar\Api\Internal;

/**
 * The PSD2 attributes extracted from a certificate's qcStatements extension
 * (ETSI TS 119 495), parsed from ASN.1 rather than string-matched.
 *
 * @see Psd2QcStatementsParser
 */
#[Internal(reason: 'Parsed ETSI TS 119 495 PSD2 QcStatement')]
final readonly class Psd2QcStatements
{
    /**
     * @param list<string> $roles PSP role codes (PSP_AS, PSP_PI, PSP_AI, PSP_IC)
     * @param string $ncaName National Competent Authority name
     * @param string $ncaId National Competent Authority identifier (e.g. GB-FCA)
     * @param bool $qualified True when the certificate carries the eIDAS QcCompliance statement
     */
    public function __construct(
        public array $roles,
        public string $ncaName,
        public string $ncaId,
        public bool $qualified,
    ) {}
}
