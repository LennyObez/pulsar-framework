<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\SellerProfile;

#[CoversClass(SellerProfile::class)]
final class SellerProfileTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $profile = new SellerProfile(
            companyName: 'Acme GmbH',
            legalForm: 'GmbH',
            vatNumber: 'DE123456789',
            registrationNumber: 'HRB 12345',
            addressLine1: 'Hauptstrasse 1',
            addressLine2: 'Building B',
            city: 'Berlin',
            postalCode: '10115',
            country: 'Germany',
            iban: 'DE89370400440532013000',
            bic: 'COBADEFFXXX',
            bankName: 'Commerzbank',
            phone: '+49 30 123456',
            email: 'billing@acme.de',
            website: 'https://acme.de',
            logoPath: '/images/logo.png',
        );

        self::assertSame('Acme GmbH', $profile->companyName);
        self::assertSame('GmbH', $profile->legalForm);
        self::assertSame('DE123456789', $profile->vatNumber);
        self::assertSame('HRB 12345', $profile->registrationNumber);
        self::assertSame('Hauptstrasse 1', $profile->addressLine1);
        self::assertSame('Building B', $profile->addressLine2);
        self::assertSame('Berlin', $profile->city);
        self::assertSame('10115', $profile->postalCode);
        self::assertSame('Germany', $profile->country);
        self::assertSame('DE89370400440532013000', $profile->iban);
        self::assertSame('COBADEFFXXX', $profile->bic);
        self::assertSame('Commerzbank', $profile->bankName);
        self::assertSame('+49 30 123456', $profile->phone);
        self::assertSame('billing@acme.de', $profile->email);
        self::assertSame('https://acme.de', $profile->website);
        self::assertSame('/images/logo.png', $profile->logoPath);
    }

    #[Test]
    public function constructorDefaultsOptionalFieldsToEmpty(): void
    {
        $profile = new SellerProfile(companyName: 'Test Ltd');

        self::assertSame('Test Ltd', $profile->companyName);
        self::assertSame('', $profile->legalForm);
        self::assertSame('', $profile->vatNumber);
        self::assertSame('', $profile->registrationNumber);
        self::assertSame('', $profile->addressLine1);
        self::assertSame('', $profile->addressLine2);
        self::assertSame('', $profile->city);
        self::assertSame('', $profile->postalCode);
        self::assertSame('', $profile->country);
        self::assertSame('', $profile->iban);
        self::assertSame('', $profile->bic);
        self::assertSame('', $profile->bankName);
        self::assertSame('', $profile->phone);
        self::assertSame('', $profile->email);
        self::assertSame('', $profile->website);
        self::assertNull($profile->logoPath);
    }

    #[Test]
    public function fromArrayBuildsFromCompleteData(): void
    {
        $data = [
            'company_name' => 'SARL Dupont',
            'legal_form' => 'SARL',
            'vat_number' => 'FR12345678901',
            'registration_number' => 'RCS Paris 123 456 789',
            'address_line1' => '15 Rue de la Paix',
            'address_line2' => '3eme etage',
            'city' => 'Paris',
            'postal_code' => '75002',
            'country' => 'France',
            'iban' => 'FR7630001007941234567890185',
            'bic' => 'BNPAFRPPXXX',
            'bank_name' => 'BNP Paribas',
            'phone' => '+33 1 23 45 67 89',
            'email' => 'facturation@dupont.fr',
            'website' => 'https://dupont.fr',
            'logo_path' => '/uploads/logo.svg',
        ];

        $profile = SellerProfile::fromArray($data);

        self::assertSame('SARL Dupont', $profile->companyName);
        self::assertSame('SARL', $profile->legalForm);
        self::assertSame('FR12345678901', $profile->vatNumber);
        self::assertSame('RCS Paris 123 456 789', $profile->registrationNumber);
        self::assertSame('15 Rue de la Paix', $profile->addressLine1);
        self::assertSame('3eme etage', $profile->addressLine2);
        self::assertSame('Paris', $profile->city);
        self::assertSame('75002', $profile->postalCode);
        self::assertSame('France', $profile->country);
        self::assertSame('FR7630001007941234567890185', $profile->iban);
        self::assertSame('BNPAFRPPXXX', $profile->bic);
        self::assertSame('BNP Paribas', $profile->bankName);
        self::assertSame('+33 1 23 45 67 89', $profile->phone);
        self::assertSame('facturation@dupont.fr', $profile->email);
        self::assertSame('https://dupont.fr', $profile->website);
        self::assertSame('/uploads/logo.svg', $profile->logoPath);
    }

    #[Test]
    public function fromArrayHandlesEmptyArray(): void
    {
        $profile = SellerProfile::fromArray([]);

        self::assertSame('', $profile->companyName);
        self::assertSame('', $profile->vatNumber);
        self::assertNull($profile->logoPath);
    }

    #[Test]
    public function fromArrayIgnoresNonStringValues(): void
    {
        $profile = SellerProfile::fromArray([
            'company_name' => 42,
            'vat_number' => true,
            'city' => ['array'],
            'logo_path' => null,
        ]);

        self::assertSame('', $profile->companyName);
        self::assertSame('', $profile->vatNumber);
        self::assertSame('', $profile->city);
        self::assertNull($profile->logoPath);
    }

    #[Test]
    public function fromArrayReturnsNullLogoForEmptyString(): void
    {
        $profile = SellerProfile::fromArray(['logo_path' => '']);

        self::assertNull($profile->logoPath);
    }

    #[Test]
    public function formattedAddressReturnsFullAddress(): void
    {
        $profile = new SellerProfile(
            companyName: 'Test',
            addressLine1: 'Keizersgracht 100',
            addressLine2: 'Suite 5',
            city: 'Amsterdam',
            postalCode: '1015 AA',
            country: 'Netherlands',
        );

        $expected = "Keizersgracht 100\nSuite 5\n1015 AA Amsterdam\nNetherlands";
        self::assertSame($expected, $profile->formattedAddress());
    }

    #[Test]
    public function formattedAddressOmitsBlankLines(): void
    {
        $profile = new SellerProfile(
            companyName: 'Test',
            addressLine1: 'Via Roma 1',
            city: 'Rome',
            postalCode: '00100',
            country: 'Italy',
        );

        $expected = "Via Roma 1\n00100 Rome\nItaly";
        self::assertSame($expected, $profile->formattedAddress());
    }

    #[Test]
    public function formattedAddressReturnsEmptyForNoData(): void
    {
        $profile = new SellerProfile(companyName: 'Test');

        self::assertSame('', $profile->formattedAddress());
    }

    #[Test]
    public function formattedAddressHandlesCityOnly(): void
    {
        $profile = new SellerProfile(companyName: 'Test', city: 'London');

        self::assertSame('London', $profile->formattedAddress());
    }

    #[Test]
    public function formattedAddressHandlesPostalCodeOnly(): void
    {
        $profile = new SellerProfile(companyName: 'Test', postalCode: '1000');

        self::assertSame('1000', $profile->formattedAddress());
    }

    #[Test]
    public function hasBankDetailsReturnsTrueWhenBothPresent(): void
    {
        $profile = new SellerProfile(
            companyName: 'Test',
            iban: 'BE68539007547034',
            bic: 'BBRUBEBB',
        );

        self::assertTrue($profile->hasBankDetails());
    }

    #[Test]
    #[DataProvider('incompleteBankDetailsProvider')]
    public function hasBankDetailsReturnsFalseWhenIncomplete(string $iban, string $bic): void
    {
        $profile = new SellerProfile(
            companyName: 'Test',
            iban: $iban,
            bic: $bic,
        );

        self::assertFalse($profile->hasBankDetails());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function incompleteBankDetailsProvider(): iterable
    {
        yield 'missing iban' => ['', 'BBRUBEBB'];
        yield 'missing bic' => ['BE68539007547034', ''];
        yield 'both missing' => ['', ''];
    }
}
