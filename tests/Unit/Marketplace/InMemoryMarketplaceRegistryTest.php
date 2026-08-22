<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Marketplace;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Marketplace\ExtensionListing;
use Pulsar\Marketplace\Internal\InMemoryMarketplaceRegistry;
use Pulsar\Marketplace\MarketplaceSearchFilter;
use Pulsar\Marketplace\MarketplaceSortOrder;

#[CoversClass(InMemoryMarketplaceRegistry::class)]
final class InMemoryMarketplaceRegistryTest extends TestCase
{
    #[Test]
    public function findReturnsRegisteredListing(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $listing = new ExtensionListing(name: 'pulsar/analytics', version: '1.0.0');
        $registry->register($listing);

        $found = $registry->find('pulsar/analytics');

        self::assertNotNull($found);
        self::assertSame('pulsar/analytics', $found->name);
    }

    #[Test]
    public function findReturnsNullForUnknown(): void
    {
        $registry = new InMemoryMarketplaceRegistry();

        self::assertNull($registry->find('nonexistent'));
    }

    #[Test]
    public function existsReturnsTrueForRegistered(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(name: 'a/b', version: '1.0.0'));

        self::assertTrue($registry->exists('a/b'));
        self::assertFalse($registry->exists('c/d'));
    }

    #[Test]
    public function versionsReturnsAvailableVersions(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $listing = new ExtensionListing(name: 'a/b', version: '2.0.0');
        $registry->register($listing, ['2.0.0', '1.5.0', '1.0.0']);

        self::assertSame(['2.0.0', '1.5.0', '1.0.0'], $registry->versions('a/b'));
    }

    #[Test]
    public function versionsDefaultsToCurrentVersion(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $listing = new ExtensionListing(name: 'a/b', version: '1.0.0');
        $registry->register($listing);

        self::assertSame(['1.0.0'], $registry->versions('a/b'));
    }

    #[Test]
    public function versionsReturnsEmptyForUnknown(): void
    {
        $registry = new InMemoryMarketplaceRegistry();

        self::assertSame([], $registry->versions('nonexistent'));
    }

    #[Test]
    public function searchByName(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(name: 'pulsar/analytics', version: '1.0.0'));
        $registry->register(new ExtensionListing(name: 'pulsar/cms', version: '1.0.0'));

        $results = $registry->search('analytics');

        self::assertCount(1, $results);
        self::assertSame('pulsar/analytics', $results[0]->name);
    }

    #[Test]
    public function searchByDescription(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(
            name: 'a/b',
            version: '1.0.0',
            description: 'Real-time analytics dashboard',
        ));

        $results = $registry->search('dashboard');

        self::assertCount(1, $results);
    }

    #[Test]
    public function searchByAuthor(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(
            name: 'a/b',
            version: '1.0.0',
            author: 'Alice',
        ));

        $results = $registry->search('alice');

        self::assertCount(1, $results);
    }

    #[Test]
    public function searchWithEmptyQueryReturnsAll(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(name: 'a/b', version: '1.0.0'));
        $registry->register(new ExtensionListing(name: 'c/d', version: '1.0.0'));

        $results = $registry->search('');

        self::assertCount(2, $results);
    }

    #[Test]
    public function searchFilterByTrustTier(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(name: 'a/b', version: '1.0.0', trustTier: TrustTier::Core));
        $registry->register(new ExtensionListing(name: 'c/d', version: '1.0.0', trustTier: TrustTier::Community));

        $filter = new MarketplaceSearchFilter(trustTier: TrustTier::Core);
        $results = $registry->search('', $filter);

        self::assertCount(1, $results);
        self::assertSame('a/b', $results[0]->name);
    }

    #[Test]
    public function searchFilterByCategory(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(
            name: 'a/b',
            version: '1.0.0',
            categories: ['analytics', 'observability'],
        ));
        $registry->register(new ExtensionListing(
            name: 'c/d',
            version: '1.0.0',
            categories: ['cms'],
        ));

        $filter = new MarketplaceSearchFilter(category: 'analytics');
        $results = $registry->search('', $filter);

        self::assertCount(1, $results);
        self::assertSame('a/b', $results[0]->name);
    }

    #[Test]
    public function searchSortByDownloads(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(name: 'a/low', version: '1.0.0', downloads: 100));
        $registry->register(new ExtensionListing(name: 'b/high', version: '1.0.0', downloads: 5000));
        $registry->register(new ExtensionListing(name: 'c/mid', version: '1.0.0', downloads: 500));

        $filter = new MarketplaceSearchFilter(sortBy: MarketplaceSortOrder::Downloads);
        $results = $registry->search('', $filter);

        self::assertSame('b/high', $results[0]->name);
        self::assertSame('c/mid', $results[1]->name);
        self::assertSame('a/low', $results[2]->name);
    }

    #[Test]
    public function searchSortByRating(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(name: 'a', version: '1.0.0', rating: 3.0));
        $registry->register(new ExtensionListing(name: 'b', version: '1.0.0', rating: 5.0));

        $filter = new MarketplaceSearchFilter(sortBy: MarketplaceSortOrder::Rating);
        $results = $registry->search('', $filter);

        self::assertSame('b', $results[0]->name);
    }

    #[Test]
    public function searchSortByName(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(name: 'z/last', version: '1.0.0'));
        $registry->register(new ExtensionListing(name: 'a/first', version: '1.0.0'));

        $filter = new MarketplaceSearchFilter(sortBy: MarketplaceSortOrder::Name);
        $results = $registry->search('', $filter);

        self::assertSame('a/first', $results[0]->name);
        self::assertSame('z/last', $results[1]->name);
    }

    #[Test]
    public function searchPagination(): void
    {
        $registry = new InMemoryMarketplaceRegistry();

        for ($i = 0; $i < 10; $i++) {
            $registry->register(new ExtensionListing(name: "vendor/pkg-{$i}", version: '1.0.0'));
        }

        $filter = new MarketplaceSearchFilter(limit: 3, offset: 2);
        $results = $registry->search('', $filter);

        self::assertCount(3, $results);
    }

    #[Test]
    public function searchNoResults(): void
    {
        $registry = new InMemoryMarketplaceRegistry();
        $registry->register(new ExtensionListing(name: 'a/b', version: '1.0.0'));

        $results = $registry->search('nonexistent-query');

        self::assertSame([], $results);
    }
}
