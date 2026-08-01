<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\BusinessProfileConfig;
use Pulsar\Config\BusinessProfileProvider;
use Pulsar\Config\ConfigRepository;

#[CoversClass(BusinessProfileProvider::class)]
final class BusinessProfileProviderTest extends TestCase
{
    #[Test]
    public function get_profile_returns_registered_config(): void
    {
        $config = new BusinessProfileConfig(
            companyName: 'Acme Corp',
            vatNumber: 'BE0123456789',
            country: 'BE',
        );

        $repository = new ConfigRepository();
        $repository->set($config);

        $provider = new BusinessProfileProvider($repository);
        $profile = $provider->getProfile();

        self::assertSame('Acme Corp', $profile->companyName);
        self::assertSame('BE0123456789', $profile->vatNumber);
        self::assertSame('BE', $profile->country);
    }

    #[Test]
    public function get_profile_returns_empty_default_when_not_registered(): void
    {
        $repository = new ConfigRepository();

        $provider = new BusinessProfileProvider($repository);
        $profile = $provider->getProfile();

        self::assertSame('', $profile->companyName);
        self::assertSame('US', $profile->country);
        self::assertNull($profile->vatNumber);
    }

    #[Test]
    public function get_seller_party_delegates_to_profile(): void
    {
        $config = new BusinessProfileConfig(
            companyName: 'Invoice Corp',
            tradingName: 'InvCorp',
            addressLine1: '456 Commerce Blvd',
            city: 'Frankfurt',
            postalCode: '60311',
            country: 'DE',
            vatNumber: 'DE123456789',
            iban: 'DE89370400440532013000',
            bic: 'COBADEFFXXX',
            bankName: 'Commerzbank',
        );

        $repository = new ConfigRepository();
        $repository->set($config);

        $provider = new BusinessProfileProvider($repository);
        $party = $provider->getSellerParty();

        self::assertSame('InvCorp', $party['name']);
        self::assertSame('456 Commerce Blvd', $party['address']['line1']);
        self::assertSame('Frankfurt', $party['address']['city']);
        self::assertSame('DE', $party['address']['country']);
        self::assertSame('DE123456789', $party['vat_number']);
        self::assertSame('DE89370400440532013000', $party['iban']);
        self::assertSame('COBADEFFXXX', $party['bic']);
        self::assertSame('Commerzbank', $party['bank_name']);
    }

    #[Test]
    public function get_seller_party_returns_defaults_when_no_config_registered(): void
    {
        $repository = new ConfigRepository();
        $provider = new BusinessProfileProvider($repository);

        $party = $provider->getSellerParty();

        self::assertSame('', $party['name']);
        self::assertSame('US', $party['address']['country']);
        self::assertNull($party['vat_number']);
        self::assertNull($party['iban']);
    }

    #[Test]
    public function seller_party_uses_company_name_when_no_trading_name(): void
    {
        $config = new BusinessProfileConfig(companyName: 'Legal Name GmbH');

        $repository = new ConfigRepository();
        $repository->set($config);

        $provider = new BusinessProfileProvider($repository);
        $party = $provider->getSellerParty();

        self::assertSame('Legal Name GmbH', $party['name']);
    }
}
