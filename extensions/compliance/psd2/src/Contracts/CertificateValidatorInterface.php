<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Psd2\Domain\CertificateInfo;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;

/**
 * Validates PSD2 eIDAS certificates (QWAC/QSEAL) per Art. 66-67.
 *
 * Implementations parse the X.509 certificate, extract PSD2-specific
 * QcStatements, validate the trust chain, and check revocation status.
 * @api
 */
#[Api(since: '1.0.0')]
interface CertificateValidatorInterface
{
    /**
     * Parse and validate a PEM-encoded certificate.
     *
     * @param string $pemCertificate PEM-encoded X.509 certificate
     *
     * @throws Psd2Exception On parse or validation failure
     */
    public function validate(string $pemCertificate): CertificateInfo;

    /**
     * Check whether a certificate's authorization number is registered.
     *
     * @param string $authorizationNumber The NCA-issued authorization number
     */
    public function isAuthorized(string $authorizationNumber): bool;
}
