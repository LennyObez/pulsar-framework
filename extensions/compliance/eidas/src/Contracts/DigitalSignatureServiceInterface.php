<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Eidas\Domain\SignatureFormat;
use Pulsar\Extension\Eidas\Domain\SignatureInfo;
use Pulsar\Extension\Eidas\Exception\EidasException;

/**
 * Electronic signature service per eIDAS Art. 25-34.
 * @api
 */
#[Api(since: '1.0.0')]
interface DigitalSignatureServiceInterface
{
    /**
     * Sign data with the specified format.
     *
     * @param string $data Data to sign
     * @param string $signerKeyId Identifier for the signer's private key
     * @param SignatureFormat $format Signature format
     *
     * @return string The encoded signature
     *
     * @throws EidasException On signing failure
     */
    public function sign(string $data, string $signerKeyId, SignatureFormat $format = SignatureFormat::JAdES): string;

    /**
     * Verify a signature and return detailed information.
     *
     * @param string $data The original data
     * @param string $signature The encoded signature
     * @param SignatureFormat $format Expected signature format
     *
     * @throws EidasException On verification failure
     */
    public function verify(string $data, string $signature, SignatureFormat $format = SignatureFormat::JAdES): SignatureInfo;
}
