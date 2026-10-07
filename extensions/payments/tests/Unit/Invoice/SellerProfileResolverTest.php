<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Invoice;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Internal\Invoice\SellerProfileResolver;

#[CoversClass(SellerProfileResolver::class)]
final class SellerProfileResolverTest extends TestCase
{
    #[Test]
    public function resolveWithNoSettingsReturnsEmptyProfile(): void
    {
        $resolver = new SellerProfileResolver();
        $profile = $resolver->resolve();

        self::assertSame('', $profile->companyName);
        self::assertSame('', $profile->vatNumber);
        self::assertSame('', $profile->country);
    }

    #[Test]
    public function resolveUsesFallbackNameWhenSettingsEmpty(): void
    {
        $resolver = new SellerProfileResolver(
            settings: null,
            fallbackCountry: 'Belgium',
            fallbackName: 'Fallback Corp',
        );

        $profile = $resolver->resolve();

        self::assertSame('Fallback Corp', $profile->companyName);
        self::assertSame('Belgium', $profile->country);
    }

    #[Test]
    public function resolveReadsFromSettingsService(): void
    {
        $settings = new class {
            /** @var array<string, string> */
            private array $data = [
                'seller_name' => 'Pulsar BV',
                'seller_legal_form' => 'BV',
                'seller_vat_number' => 'BE0123456789',
                'seller_registration_number' => 'KBO 0123.456.789',
                'seller_address_line1' => 'Koningsstraat 1',
                'seller_address_line2' => '',
                'seller_city' => 'Brussels',
                'seller_postal_code' => '1000',
                'seller_country' => 'Belgium',
                'seller_iban' => 'BE68539007547034',
                'seller_bic' => 'BBRUBEBB',
                'seller_bank_name' => 'KBC Bank',
                'seller_phone' => '+32 2 123 45 67',
                'seller_email' => 'billing@pulsar.io',
                'seller_website' => 'https://pulsar.io',
                'seller_logo_path' => '/logo.svg',
            ];

            public function get(string $group, string $key): mixed
            {
                if ($group !== 'commerce') {
                    return null;
                }

                return $this->data[$key] ?? null;
            }
        };

        $resolver = new SellerProfileResolver(settings: $settings);
        $profile = $resolver->resolve();

        self::assertSame('Pulsar BV', $profile->companyName);
        self::assertSame('BV', $profile->legalForm);
        self::assertSame('BE0123456789', $profile->vatNumber);
        self::assertSame('KBO 0123.456.789', $profile->registrationNumber);
        self::assertSame('Koningsstraat 1', $profile->addressLine1);
        self::assertSame('Brussels', $profile->city);
        self::assertSame('1000', $profile->postalCode);
        self::assertSame('Belgium', $profile->country);
        self::assertSame('BE68539007547034', $profile->iban);
        self::assertSame('BBRUBEBB', $profile->bic);
        self::assertSame('KBC Bank', $profile->bankName);
        self::assertSame('+32 2 123 45 67', $profile->phone);
        self::assertSame('billing@pulsar.io', $profile->email);
        self::assertSame('https://pulsar.io', $profile->website);
        self::assertSame('/logo.svg', $profile->logoPath);
    }

    #[Test]
    public function resolveSettingsOverrideFallbacks(): void
    {
        $settings = new class {
            public function get(string $group, string $key): mixed
            {
                if ($group !== 'commerce') {
                    return null;
                }

                return match ($key) {
                    'seller_name' => 'Settings Corp',
                    'seller_country' => 'Luxembourg',
                    default => null,
                };
            }
        };

        $resolver = new SellerProfileResolver(
            settings: $settings,
            fallbackCountry: 'Belgium',
            fallbackName: 'Fallback Corp',
        );

        $profile = $resolver->resolve();

        self::assertSame('Settings Corp', $profile->companyName);
        self::assertSame('Luxembourg', $profile->country);
    }

    #[Test]
    public function resolveFallbacksApplyWhenSettingsReturnEmpty(): void
    {
        $settings = new class {
            public function get(string $group, string $key): mixed
            {
                return '';
            }
        };

        $resolver = new SellerProfileResolver(
            settings: $settings,
            fallbackCountry: 'France',
            fallbackName: 'Fallback SARL',
        );

        $profile = $resolver->resolve();

        self::assertSame('Fallback SARL', $profile->companyName);
        self::assertSame('France', $profile->country);
    }

    #[Test]
    public function resolveHandlesSettingsReturningNonStringValues(): void
    {
        $settings = new class {
            public function get(string $group, string $key): mixed
            {
                return match ($key) {
                    'seller_name' => 42,
                    'seller_vat_number' => true,
                    'seller_city' => ['not', 'a', 'string'],
                    default => null,
                };
            }
        };

        $resolver = new SellerProfileResolver(settings: $settings);
        $profile = $resolver->resolve();

        self::assertSame('', $profile->companyName);
        self::assertSame('', $profile->vatNumber);
        self::assertSame('', $profile->city);
    }

    #[Test]
    public function resolveHandlesObjectWithoutGetMethod(): void
    {
        $noGetObject = new class {};

        $resolver = new SellerProfileResolver(
            settings: $noGetObject,
            fallbackName: 'Test Corp',
        );

        $profile = $resolver->resolve();

        self::assertSame('Test Corp', $profile->companyName);
    }
}
