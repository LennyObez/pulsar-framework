<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Eidas\Domain\SealInfo;
use Pulsar\Extension\Eidas\Domain\SignatureFormat;
use Pulsar\Extension\Eidas\Exception\EidasException;

/**
 * Electronic seal service per eIDAS Art. 35-40.
 *
 * Organization-level seals for automated document integrity.
 * @api
 */
#[Api(since: '1.0.0')]
interface ElectronicSealServiceInterface
{
    /**
     * Seal data with the organization's seal.
     *
     * @param string $data Data to seal
     * @param string $sealKeyId Identifier for the organization's seal key
     * @param SignatureFormat $format Seal format
     *
     * @return string The encoded seal
     *
     * @throws EidasException On sealing failure
     */
    public function seal(string $data, string $sealKeyId, SignatureFormat $format = SignatureFormat::JAdES): string;

    /**
     * Verify a seal and return detailed information.
     *
     * @param string $data The original data
     * @param string $seal The encoded seal
     * @param SignatureFormat $format Expected seal format
     *
     * @throws EidasException On verification failure
     */
    public function verifySeal(string $data, string $seal, SignatureFormat $format = SignatureFormat::JAdES): SealInfo;
}
