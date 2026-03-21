<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Udi;

use Pulsar\Api\Api;

use function preg_match;
use function strlen;

/**
 * Validates UDI format based on issuing agency rules.
 *
 * This is a FORMAT validator; it checks structural validity against
 * the issuing agency's encoding rules. It does not verify that the
 * device is registered in EUDAMED or any UDI database.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class UdiValidator
{
    /**
     * Validate a UDI device identifier format.
     *
     * @return UdiValidationResult Result with validity flag and any error details
     */
    public function validate(string $deviceIdentifier, UdiIssuingAgency $agency): UdiValidationResult
    {
        if ($deviceIdentifier === '') {
            return new UdiValidationResult(false, 'Device identifier must not be empty');
        }

        return match ($agency) {
            UdiIssuingAgency::GS1 => $this->validateGs1($deviceIdentifier),
            UdiIssuingAgency::HIBCC => $this->validateHibcc($deviceIdentifier),
            UdiIssuingAgency::ICCBBA => $this->validateIccbba($deviceIdentifier),
            UdiIssuingAgency::IFA => $this->validateIfa($deviceIdentifier),
        };
    }

    /**
     * GS1: GTIN format: 14 digits with valid check digit.
     */
    private function validateGs1(string $di): UdiValidationResult
    {
        if (preg_match('/^\d{14}$/', $di) !== 1) {
            return new UdiValidationResult(
                false,
                'GS1 device identifier must be exactly 14 digits (GTIN-14)',
            );
        }

        if (!$this->isValidGtinCheckDigit($di)) {
            return new UdiValidationResult(false, 'Invalid GS1 check digit');
        }

        return new UdiValidationResult(true);
    }

    /**
     * HIBCC: Starts with '+', alphanumeric, 4-24 characters total.
     */
    private function validateHibcc(string $di): UdiValidationResult
    {
        if (preg_match('/^\+[A-Z0-9]{3,23}$/', $di) !== 1) {
            return new UdiValidationResult(
                false,
                'HIBCC device identifier must start with "+" followed by 3-23 alphanumeric characters',
            );
        }

        return new UdiValidationResult(true);
    }

    /**
     * ICCBBA: Starts with '=', followed by alphanumeric content, 7-25 characters total.
     */
    private function validateIccbba(string $di): UdiValidationResult
    {
        if (preg_match('/^=[A-Z0-9]{6,24}$/', $di) !== 1) {
            return new UdiValidationResult(
                false,
                'ICCBBA device identifier must start with "=" followed by 6-24 alphanumeric characters',
            );
        }

        return new UdiValidationResult(true);
    }

    /**
     * IFA: PPN format: numeric with check character, 4-22 characters.
     */
    private function validateIfa(string $di): UdiValidationResult
    {
        if (preg_match('/^[0-9A-Z]{4,22}$/', $di) !== 1) {
            return new UdiValidationResult(
                false,
                'IFA device identifier must be 4-22 alphanumeric characters',
            );
        }

        return new UdiValidationResult(true);
    }

    /**
     * Validate GTIN-14 check digit using the standard algorithm.
     */
    private function isValidGtinCheckDigit(string $gtin): bool
    {
        $sum = 0;
        $len = strlen($gtin);

        for ($i = 0; $i < $len - 1; $i++) {
            $digit = (int) $gtin[$i];
            $multiplier = ($len - 1 - $i) % 2 === 0 ? 1 : 3;
            $sum += $digit * $multiplier;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return $checkDigit === (int) $gtin[$len - 1];
    }
}
