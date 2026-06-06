<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Invoice;

use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Domain\SellerProfile;

use function is_string;

/**
 * Resolves the seller profile from application settings.
 *
 * Reads company information from the CMS settings store via the
 * `commerce` group. Falls back to configuration defaults when
 * the settings service is unavailable.
 *
 * Settings keys (group: `commerce`):
 *   seller_name, seller_legal_form, seller_vat_number,
 *   seller_registration_number, seller_address_line1,
 *   seller_address_line2, seller_city, seller_postal_code,
 *   seller_country, seller_iban, seller_bic, seller_bank_name,
 *   seller_phone, seller_email, seller_website, seller_logo_path
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Use SellerProfile DTO directly in templates')]
final readonly class SellerProfileResolver
{
    /**
     * @param ?object $settings CMS SettingsServiceInterface (nullable to avoid hard coupling)
     * @param string $fallbackCountry Fallback country from PaymentsConfig or CommerceConfig
     * @param string $fallbackName    Fallback company name
     */
    public function __construct(
        private ?object $settings = null,
        private string $fallbackCountry = '',
        private string $fallbackName = '',
    ) {}

    /**
     * Resolve the seller profile from settings.
     */
    public function resolve(): SellerProfile
    {
        $data = $this->loadFromSettings();

        if ($data['company_name'] === '' && $this->fallbackName !== '') {
            $data['company_name'] = $this->fallbackName;
        }

        if ($data['country'] === '' && $this->fallbackCountry !== '') {
            $data['country'] = $this->fallbackCountry;
        }

        return SellerProfile::fromArray($data);
    }

    /**
     * @return array<string, string>
     */
    private function loadFromSettings(): array
    {
        if ($this->settings === null || !method_exists($this->settings, 'get')) {
            return $this->emptyData();
        }

        return [
            'company_name' => $this->readSetting('seller_name'),
            'legal_form' => $this->readSetting('seller_legal_form'),
            'vat_number' => $this->readSetting('seller_vat_number'),
            'registration_number' => $this->readSetting('seller_registration_number'),
            'address_line1' => $this->readSetting('seller_address_line1'),
            'address_line2' => $this->readSetting('seller_address_line2'),
            'city' => $this->readSetting('seller_city'),
            'postal_code' => $this->readSetting('seller_postal_code'),
            'country' => $this->readSetting('seller_country'),
            'iban' => $this->readSetting('seller_iban'),
            'bic' => $this->readSetting('seller_bic'),
            'bank_name' => $this->readSetting('seller_bank_name'),
            'phone' => $this->readSetting('seller_phone'),
            'email' => $this->readSetting('seller_email'),
            'website' => $this->readSetting('seller_website'),
            'logo_path' => $this->readSetting('seller_logo_path'),
        ];
    }

    private function readSetting(string $key): string
    {
        if ($this->settings === null || !method_exists($this->settings, 'get')) {
            return '';
        }

        /** @var mixed $value */
        $value = $this->settings->get('commerce', $key);

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, string>
     */
    private function emptyData(): array
    {
        return [
            'company_name' => '',
            'legal_form' => '',
            'vat_number' => '',
            'registration_number' => '',
            'address_line1' => '',
            'address_line2' => '',
            'city' => '',
            'postal_code' => '',
            'country' => '',
            'iban' => '',
            'bic' => '',
            'bank_name' => '',
            'phone' => '',
            'email' => '',
            'website' => '',
            'logo_path' => '',
        ];
    }
}
