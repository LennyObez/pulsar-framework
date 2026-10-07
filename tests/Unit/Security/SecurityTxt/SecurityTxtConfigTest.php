<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\SecurityTxt;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\SecurityTxt\SecurityTxtConfig;

#[CoversClass(SecurityTxtConfig::class)]
final class SecurityTxtConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreEmpty(): void
    {
        $config = new SecurityTxtConfig();

        self::assertSame([], $config->contacts);
        self::assertSame('', $config->expires);
        self::assertSame('', $config->encryption);
        self::assertSame('', $config->acknowledgments);
        self::assertSame('', $config->policy);
        self::assertSame([], $config->preferredLanguages);
        self::assertSame('', $config->canonical);
        self::assertSame([], $config->hiring);
    }

    #[Test]
    public function constructionStoresAllProperties(): void
    {
        $config = new SecurityTxtConfig(
            contacts: ['mailto:security@example.com', 'https://example.com/report'],
            expires: '2027-01-01T00:00:00+00:00',
            encryption: 'https://example.com/pgp-key.txt',
            acknowledgments: 'https://example.com/hall-of-fame',
            policy: 'https://example.com/security-policy',
            preferredLanguages: ['en', 'fr'],
            canonical: 'https://example.com/.well-known/security.txt',
            hiring: ['https://example.com/careers/security'],
        );

        self::assertSame(['mailto:security@example.com', 'https://example.com/report'], $config->contacts);
        self::assertSame('2027-01-01T00:00:00+00:00', $config->expires);
        self::assertSame('https://example.com/pgp-key.txt', $config->encryption);
        self::assertSame('https://example.com/hall-of-fame', $config->acknowledgments);
        self::assertSame('https://example.com/security-policy', $config->policy);
        self::assertSame(['en', 'fr'], $config->preferredLanguages);
        self::assertSame('https://example.com/.well-known/security.txt', $config->canonical);
        self::assertSame(['https://example.com/careers/security'], $config->hiring);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = SecurityTxtConfig::fromArray([
            'contacts' => ['mailto:sec@test.com'],
            'expires' => '2027-06-01T00:00:00+00:00',
            'encryption' => 'https://test.com/key',
            'acknowledgments' => 'https://test.com/thanks',
            'policy' => 'https://test.com/policy',
            'preferred_languages' => ['de'],
            'canonical' => 'https://test.com/.well-known/security.txt',
            'hiring' => ['https://test.com/jobs/sec-eng'],
        ]);

        self::assertSame(['mailto:sec@test.com'], $config->contacts);
        self::assertSame('2027-06-01T00:00:00+00:00', $config->expires);
        self::assertSame('https://test.com/key', $config->encryption);
        self::assertSame('https://test.com/thanks', $config->acknowledgments);
        self::assertSame('https://test.com/policy', $config->policy);
        self::assertSame(['de'], $config->preferredLanguages);
        self::assertSame('https://test.com/.well-known/security.txt', $config->canonical);
        self::assertSame(['https://test.com/jobs/sec-eng'], $config->hiring);
    }

    #[Test]
    public function fromArrayDefaultsOnMissingKeys(): void
    {
        $config = SecurityTxtConfig::fromArray([]);

        self::assertSame([], $config->contacts);
        self::assertSame('', $config->expires);
        self::assertSame('', $config->encryption);
        self::assertSame('', $config->acknowledgments);
        self::assertSame('', $config->policy);
        self::assertSame([], $config->preferredLanguages);
        self::assertSame('', $config->canonical);
        self::assertSame([], $config->hiring);
    }

    #[Test]
    public function fromArrayFiltersNonStringContacts(): void
    {
        $config = SecurityTxtConfig::fromArray([
            'contacts' => ['mailto:valid@test.com', 42, null, 'https://also-valid.com'],
        ]);

        self::assertSame(['mailto:valid@test.com', 'https://also-valid.com'], $config->contacts);
    }

    #[Test]
    public function fromArrayHandlesNonArrayContacts(): void
    {
        $config = SecurityTxtConfig::fromArray([
            'contacts' => 'not-an-array',
        ]);

        self::assertSame([], $config->contacts);
    }

    #[Test]
    public function fromArrayHandlesNonStringScalars(): void
    {
        $config = SecurityTxtConfig::fromArray([
            'expires' => 123,
            'encryption' => false,
            'policy' => [],
        ]);

        self::assertSame('', $config->expires);
        self::assertSame('', $config->encryption);
        self::assertSame('', $config->policy);
    }
}
