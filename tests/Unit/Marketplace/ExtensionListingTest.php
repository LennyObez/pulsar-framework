<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Marketplace;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Marketplace\ExtensionListing;

#[CoversClass(ExtensionListing::class)]
final class ExtensionListingTest extends TestCase
{
    #[Test]
    public function constructionWithDefaults(): void
    {
        $listing = new ExtensionListing(name: 'vendor/pkg', version: '1.0.0');

        self::assertSame('vendor/pkg', $listing->name);
        self::assertSame('1.0.0', $listing->version);
        self::assertSame('', $listing->author);
        self::assertSame('', $listing->description);
        self::assertSame(TrustTier::Community, $listing->trustTier);
        self::assertSame(0, $listing->downloads);
        self::assertSame(0.0, $listing->rating);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $listing = ExtensionListing::fromArray([
            'name' => 'pulsar/analytics',
            'version' => '2.1.0',
            'author' => 'Pulsar Team',
            'description' => 'Analytics extension',
            'trust_tier' => 'verified',
            'downloads' => 5000,
            'rating' => 4.5,
            'pulsar_min_version' => '1.0.0',
            'pulsar_max_version' => '2.0.0',
            'categories' => ['analytics', 'observability'],
            'homepage' => 'https://example.com',
            'license' => 'MIT',
        ]);

        self::assertSame('pulsar/analytics', $listing->name);
        self::assertSame('2.1.0', $listing->version);
        self::assertSame('Pulsar Team', $listing->author);
        self::assertSame(TrustTier::Verified, $listing->trustTier);
        self::assertSame(5000, $listing->downloads);
        self::assertSame(4.5, $listing->rating);
        self::assertSame(['analytics', 'observability'], $listing->categories);
        self::assertSame('MIT', $listing->license);
    }

    #[Test]
    public function fromArrayWithEmptyArray(): void
    {
        $listing = ExtensionListing::fromArray([]);

        self::assertSame('', $listing->name);
        self::assertSame('0.0.0', $listing->version);
        self::assertSame(TrustTier::Community, $listing->trustTier);
    }

    #[Test]
    public function shortName(): void
    {
        $listing = new ExtensionListing(name: 'pulsar/analytics', version: '1.0.0');

        self::assertSame('analytics', $listing->shortName());
    }

    #[Test]
    public function shortNameWithoutVendor(): void
    {
        $listing = new ExtensionListing(name: 'standalone', version: '1.0.0');

        self::assertSame('standalone', $listing->shortName());
    }

    #[Test]
    public function vendor(): void
    {
        $listing = new ExtensionListing(name: 'pulsar/analytics', version: '1.0.0');

        self::assertSame('pulsar', $listing->vendor());
    }

    #[Test]
    public function vendorWithoutPrefix(): void
    {
        $listing = new ExtensionListing(name: 'standalone', version: '1.0.0');

        self::assertSame('', $listing->vendor());
    }

    #[Test]
    public function isOfficial(): void
    {
        $official = new ExtensionListing(name: 'a', version: '1.0.0', trustTier: TrustTier::Core);
        $community = new ExtensionListing(name: 'b', version: '1.0.0', trustTier: TrustTier::Community);

        self::assertTrue($official->isOfficial());
        self::assertFalse($community->isOfficial());
    }

    #[Test]
    public function isVerified(): void
    {
        $core = new ExtensionListing(name: 'a', version: '1.0.0', trustTier: TrustTier::Core);
        $verified = new ExtensionListing(name: 'b', version: '1.0.0', trustTier: TrustTier::Verified);
        $community = new ExtensionListing(name: 'c', version: '1.0.0', trustTier: TrustTier::Community);

        self::assertTrue($core->isVerified());
        self::assertTrue($verified->isVerified());
        self::assertFalse($community->isVerified());
    }

    #[Test]
    public function fromArrayFiltersNonStringCategories(): void
    {
        $listing = ExtensionListing::fromArray([
            'name' => 'test',
            'version' => '1.0.0',
            'categories' => ['valid', 42, null, 'also-valid'],
        ]);

        self::assertSame(['valid', 'also-valid'], $listing->categories);
    }
}
