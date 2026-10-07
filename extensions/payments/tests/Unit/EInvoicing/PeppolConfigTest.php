<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\EInvoicing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\EInvoicing\PeppolConfig;

final class PeppolConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = PeppolConfig::fromArray([
            'enabled' => true,
            'access_point_url' => 'https://peppol.example.com/as4',
            'sender_id' => '5412345000013',
            'sender_scheme' => '0088',
            'receiver_lookup_endpoint' => 'https://sml.example.com',
            'signing_key_path' => '/keys/peppol.pem',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('https://peppol.example.com/as4', $config->accessPointUrl);
        self::assertSame('5412345000013', $config->senderId);
        self::assertSame('0088', $config->senderScheme);
        self::assertSame('https://sml.example.com', $config->receiverLookupEndpoint);
        self::assertSame('/keys/peppol.pem', $config->signingKeyPath);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = PeppolConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('', $config->accessPointUrl);
        self::assertSame('', $config->senderId);
        self::assertSame('0088', $config->senderScheme);
        self::assertNull($config->receiverLookupEndpoint);
        self::assertNull($config->signingKeyPath);
    }

    #[Test]
    public function disabledFactory(): void
    {
        $config = PeppolConfig::disabled();

        self::assertFalse($config->enabled);
        self::assertSame('', $config->accessPointUrl);
        self::assertSame('', $config->senderId);
        self::assertSame('0088', $config->senderScheme);
    }

    #[Test]
    public function fromArrayIgnoresNonStringValues(): void
    {
        $config = PeppolConfig::fromArray([
            'enabled' => 1,
            'access_point_url' => 42,
            'sender_id' => ['not', 'a', 'string'],
            'sender_scheme' => null,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->accessPointUrl);
        self::assertSame('', $config->senderId);
        self::assertSame('0088', $config->senderScheme);
    }
}
