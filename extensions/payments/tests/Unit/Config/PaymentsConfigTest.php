<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\PaymentsConfig;

final class PaymentsConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = PaymentsConfig::fromArray([
            'provider' => 'stripe',
            'default_currency' => 'EUR',
            'webhook' => ['secret' => 'whsec_test', 'path' => '/hooks/pay'],
            'idempotency' => ['ttl_seconds' => 3600, 'store' => 'redis'],
            'webhook_log' => ['ttl_seconds' => 86400],
        ]);

        self::assertSame('stripe', $config->provider);
        self::assertSame('EUR', $config->defaultCurrency);
        self::assertSame('whsec_test', $config->webhook->secret);
        self::assertSame('/hooks/pay', $config->webhook->path);
        self::assertSame(3600, $config->idempotency->ttlSeconds);
        self::assertSame('redis', $config->idempotency->store);
        self::assertSame(86400, $config->webhookLog->ttlSeconds);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = PaymentsConfig::fromArray([]);

        self::assertSame('null', $config->provider);
        self::assertSame('USD', $config->defaultCurrency);
        self::assertSame('', $config->webhook->secret);
        self::assertSame('/webhooks/payments', $config->webhook->path);
        self::assertSame(86400, $config->idempotency->ttlSeconds);
        self::assertSame('memory', $config->idempotency->store);
        self::assertSame([], $config->countryPaymentMethods);
    }

    #[Test]
    public function fromArrayParsesCountryPaymentMethods(): void
    {
        $config = PaymentsConfig::fromArray([
            'country_payment_methods' => [
                'BE' => ['card', 'bancontact', 'payconiq'],
                'NL' => ['card', 'ideal'],
            ],
        ]);

        self::assertSame(['card', 'bancontact', 'payconiq'], $config->countryPaymentMethods['BE']);
        self::assertSame(['card', 'ideal'], $config->countryPaymentMethods['NL']);
    }

    #[Test]
    public function fromArrayCountryPaymentMethodsDefaultsToEmpty(): void
    {
        $config = PaymentsConfig::fromArray([]);

        self::assertSame([], $config->countryPaymentMethods);
    }

    #[Test]
    public function fromArrayIgnoresInvalidCountryPaymentMethodEntries(): void
    {
        $config = PaymentsConfig::fromArray([
            'country_payment_methods' => [
                'BE' => ['card', 42, null, 'bancontact'],
                123 => ['card'],  // non-string key
                'NL' => 'not-an-array',  // non-array value
            ],
        ]);

        // Only valid entries survive
        self::assertArrayHasKey('BE', $config->countryPaymentMethods);
        self::assertSame(['card', 'bancontact'], $config->countryPaymentMethods['BE']);
        self::assertArrayNotHasKey('NL', $config->countryPaymentMethods);
    }

    #[Test]
    public function fromArrayCountryCodesAreUppercased(): void
    {
        $config = PaymentsConfig::fromArray([
            'country_payment_methods' => [
                'be' => ['card', 'bancontact'],
            ],
        ]);

        self::assertArrayHasKey('BE', $config->countryPaymentMethods);
    }
}
