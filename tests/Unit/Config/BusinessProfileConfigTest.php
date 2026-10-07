<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\BusinessProfileConfig;
use Pulsar\Config\Environment;

#[CoversClass(BusinessProfileConfig::class)]
final class BusinessProfileConfigTest extends TestCase
{
    #[Test]
    public function defaults_are_applied_when_constructed_without_arguments(): void
    {
        $config = new BusinessProfileConfig();

        self::assertSame('', $config->companyName);
        self::assertNull($config->tradingName);
        self::assertNull($config->legalForm);
        self::assertNull($config->registrationNumber);
        self::assertNull($config->vatNumber);
        self::assertNull($config->taxId);
        self::assertNull($config->addressLine1);
        self::assertNull($config->addressLine2);
        self::assertNull($config->city);
        self::assertNull($config->postalCode);
        self::assertNull($config->region);
        self::assertSame('US', $config->country);
        self::assertNull($config->phone);
        self::assertNull($config->email);
        self::assertNull($config->website);
        self::assertNull($config->iban);
        self::assertNull($config->bic);
        self::assertNull($config->bankName);
        self::assertNull($config->logoPath);
        self::assertNull($config->peppolId);
        self::assertNull($config->peppolScheme);
    }

    #[Test]
    public function from_array_populates_all_fields_from_config_data(): void
    {
        $data = [
            'company_name' => 'Acme Corporation',
            'trading_name' => 'Acme',
            'legal_form' => 'LLC',
            'registration_number' => 'REG-12345',
            'vat_number' => 'BE0123456789',
            'tax_id' => 'EIN-98765',
            'address_line1' => '123 Main Street',
            'address_line2' => 'Suite 100',
            'city' => 'Brussels',
            'postal_code' => '1000',
            'region' => 'Brussels-Capital',
            'country' => 'BE',
            'phone' => '+32 2 123 45 67',
            'email' => 'contact@acme.be',
            'website' => 'https://acme.be',
            'iban' => 'BE71096123456769',
            'bic' => 'GKCCBEBB',
            'bank_name' => 'Belfius',
            'logo_path' => 'images/logo.svg',
            'peppol_id' => '0208:0123456789',
            'peppol_scheme' => '0208',
        ];

        $config = BusinessProfileConfig::fromArray($data, Environment::load());

        self::assertSame('Acme Corporation', $config->companyName);
        self::assertSame('Acme', $config->tradingName);
        self::assertSame('LLC', $config->legalForm);
        self::assertSame('REG-12345', $config->registrationNumber);
        self::assertSame('BE0123456789', $config->vatNumber);
        self::assertSame('EIN-98765', $config->taxId);
        self::assertSame('123 Main Street', $config->addressLine1);
        self::assertSame('Suite 100', $config->addressLine2);
        self::assertSame('Brussels', $config->city);
        self::assertSame('1000', $config->postalCode);
        self::assertSame('Brussels-Capital', $config->region);
        self::assertSame('BE', $config->country);
        self::assertSame('+32 2 123 45 67', $config->phone);
        self::assertSame('contact@acme.be', $config->email);
        self::assertSame('https://acme.be', $config->website);
        self::assertSame('BE71096123456769', $config->iban);
        self::assertSame('GKCCBEBB', $config->bic);
        self::assertSame('Belfius', $config->bankName);
        self::assertSame('images/logo.svg', $config->logoPath);
        self::assertSame('0208:0123456789', $config->peppolId);
        self::assertSame('0208', $config->peppolScheme);
    }

    #[Test]
    public function from_array_uses_defaults_for_empty_data(): void
    {
        $config = BusinessProfileConfig::fromArray([], Environment::load());

        self::assertSame('', $config->companyName);
        self::assertSame('US', $config->country);
        self::assertNull($config->tradingName);
        self::assertNull($config->vatNumber);
    }

    #[Test]
    public function from_array_ignores_non_string_values(): void
    {
        $data = [
            'company_name' => 42,
            'trading_name' => ['array'],
            'vat_number' => true,
            'country' => 123,
        ];

        $config = BusinessProfileConfig::fromArray($data, Environment::load());

        self::assertSame('', $config->companyName);
        self::assertNull($config->tradingName);
        self::assertNull($config->vatNumber);
        self::assertSame('US', $config->country);
    }

    #[Test]
    public function display_name_returns_trading_name_when_set(): void
    {
        $config = new BusinessProfileConfig(companyName: 'Acme Corp LLC', tradingName: 'Acme');

        self::assertSame('Acme', $config->displayName());
    }

    #[Test]
    public function display_name_falls_back_to_company_name(): void
    {
        $config = new BusinessProfileConfig(companyName: 'Acme Corp LLC');

        self::assertSame('Acme Corp LLC', $config->displayName());
    }

    #[Test]
    public function formatted_address_builds_full_address_string(): void
    {
        $config = new BusinessProfileConfig(
            addressLine1: '123 Main Street',
            addressLine2: 'Suite 100',
            city: 'Brussels',
            postalCode: '1000',
            region: 'Brussels-Capital',
            country: 'BE',
        );

        $address = $config->formattedAddress();

        self::assertStringContainsString('123 Main Street', $address);
        self::assertStringContainsString('Suite 100', $address);
        self::assertStringContainsString('Brussels', $address);
        self::assertStringContainsString('1000', $address);
        self::assertStringContainsString('Brussels-Capital', $address);
        self::assertStringContainsString('BE', $address);
    }

    #[Test]
    public function formatted_address_omits_null_parts(): void
    {
        $config = new BusinessProfileConfig(
            addressLine1: '123 Main Street',
            city: 'Brussels',
            country: 'BE',
        );

        $address = $config->formattedAddress();

        self::assertSame('123 Main Street, Brussels, BE', $address);
    }

    #[Test]
    public function formatted_address_handles_minimal_data(): void
    {
        $config = new BusinessProfileConfig(country: 'US');

        $address = $config->formattedAddress();

        self::assertSame('US', $address);
    }

    #[Test]
    public function seller_party_returns_structured_invoice_data(): void
    {
        $config = new BusinessProfileConfig(
            companyName: 'Acme Corp',
            tradingName: 'Acme',
            vatNumber: 'BE0123456789',
            taxId: 'EIN-99',
            registrationNumber: 'REG-001',
            addressLine1: '123 Main',
            city: 'Brussels',
            postalCode: '1000',
            country: 'BE',
            email: 'invoicing@acme.be',
            phone: '+32 2 000 00 00',
            iban: 'BE71096123456769',
            bic: 'GKCCBEBB',
            bankName: 'Belfius',
            peppolId: '0208:0123',
            peppolScheme: '0208',
        );

        $party = $config->sellerParty();

        self::assertSame('Acme', $party['name']);
        self::assertSame('123 Main', $party['address']['line1']);
        self::assertSame('Brussels', $party['address']['city']);
        self::assertSame('BE', $party['address']['country']);
        self::assertSame('BE0123456789', $party['vat_number']);
        self::assertSame('EIN-99', $party['tax_id']);
        self::assertSame('REG-001', $party['registration_number']);
        self::assertSame('invoicing@acme.be', $party['email']);
        self::assertSame('+32 2 000 00 00', $party['phone']);
        self::assertSame('BE71096123456769', $party['iban']);
        self::assertSame('GKCCBEBB', $party['bic']);
        self::assertSame('Belfius', $party['bank_name']);
        self::assertSame('0208:0123', $party['peppol_id']);
        self::assertSame('0208', $party['peppol_scheme']);
    }

    #[Test]
    public function is_complete_for_invoicing_requires_minimum_fields(): void
    {
        $incomplete = new BusinessProfileConfig();
        self::assertFalse($incomplete->isCompleteForInvoicing());

        $noAddress = new BusinessProfileConfig(companyName: 'Acme');
        self::assertFalse($noAddress->isCompleteForInvoicing());

        $noCity = new BusinessProfileConfig(companyName: 'Acme', addressLine1: '123 Main');
        self::assertFalse($noCity->isCompleteForInvoicing());

        $complete = new BusinessProfileConfig(
            companyName: 'Acme',
            addressLine1: '123 Main',
            city: 'Brussels',
            country: 'BE',
        );
        self::assertTrue($complete->isCompleteForInvoicing());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function environmentOverrideProvider(): iterable
    {
        yield 'company name' => ['BUSINESS_NAME', 'EnvCorp', 'companyName'];
        yield 'country' => ['BUSINESS_COUNTRY', 'DE', 'country'];
        yield 'vat number' => ['BUSINESS_VAT_NUMBER', 'DE123456789', 'vatNumber'];
        yield 'email' => ['BUSINESS_EMAIL', 'env@corp.de', 'email'];
        yield 'iban' => ['BUSINESS_IBAN', 'DE89370400440532013000', 'iban'];
    }

    #[Test]
    #[DataProvider('environmentOverrideProvider')]
    public function environment_variables_override_config_values(
        string $envKey,
        string $envValue,
        string $property,
    ): void {
        // Set the env var temporarily
        $previousValue = getenv($envKey);
        putenv("$envKey=$envValue");

        try {
            $config = BusinessProfileConfig::fromArray([
                'company_name' => 'FileValue',
                'country' => 'FR',
                'vat_number' => 'FR12345',
                'email' => 'file@example.com',
                'iban' => 'FR7630006000011234567890189',
            ], Environment::load());

            self::assertSame($envValue, $config->{$property});
        } finally {
            // Restore
            if ($previousValue === false) {
                putenv($envKey);
            } else {
                putenv("$envKey=$previousValue");
            }
        }
    }
}
