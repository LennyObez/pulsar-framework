<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Catalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Catalog\ChainCatalog;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\TranslationEntry;

#[CoversClass(ChainCatalog::class)]
final class ChainCatalogTest extends TestCase
{
    #[Test]
    public function getReturnsFirstMatchingEntry(): void
    {
        $entry1 = new TranslationEntry(key: 'welcome', message: 'From catalog 1');
        $entry2 = new TranslationEntry(key: 'welcome', message: 'From catalog 2');

        $catalog1 = $this->createStub(CatalogInterface::class);
        $catalog1->method('get')->willReturn($entry1);

        $catalog2 = $this->createStub(CatalogInterface::class);
        $catalog2->method('get')->willReturn($entry2);

        $chain = new ChainCatalog($catalog1, $catalog2);

        $result = $chain->get('welcome', 'en');

        self::assertNotNull($result);
        self::assertSame('From catalog 1', $result->message);
    }

    #[Test]
    public function getFallsToSecondCatalog(): void
    {
        $entry = new TranslationEntry(key: 'welcome', message: 'From catalog 2');

        $catalog1 = $this->createStub(CatalogInterface::class);
        $catalog1->method('get')->willReturn(null);

        $catalog2 = $this->createStub(CatalogInterface::class);
        $catalog2->method('get')->willReturn($entry);

        $chain = new ChainCatalog($catalog1, $catalog2);

        $result = $chain->get('welcome', 'en');

        self::assertNotNull($result);
        self::assertSame('From catalog 2', $result->message);
    }

    #[Test]
    public function getReturnsNullWhenNoCatalogHasKey(): void
    {
        $catalog1 = $this->createStub(CatalogInterface::class);
        $catalog1->method('get')->willReturn(null);

        $chain = new ChainCatalog($catalog1);

        self::assertNull($chain->get('missing', 'en'));
    }

    #[Test]
    public function hasReturnsTrueIfAnyCatalogHasKey(): void
    {
        $catalog1 = $this->createStub(CatalogInterface::class);
        $catalog1->method('has')->willReturn(false);

        $catalog2 = $this->createStub(CatalogInterface::class);
        $catalog2->method('has')->willReturn(true);

        $chain = new ChainCatalog($catalog1, $catalog2);

        self::assertTrue($chain->has('key', 'en'));
    }

    #[Test]
    public function allMergesWithFirstCatalogWinning(): void
    {
        $entry1 = new TranslationEntry(key: 'welcome', message: 'First');
        $entry2 = new TranslationEntry(key: 'welcome', message: 'Second');
        $entry3 = new TranslationEntry(key: 'goodbye', message: 'Bye');

        $catalog1 = $this->createStub(CatalogInterface::class);
        $catalog1->method('all')->willReturn(['welcome' => $entry1]);

        $catalog2 = $this->createStub(CatalogInterface::class);
        $catalog2->method('all')->willReturn(['welcome' => $entry2, 'goodbye' => $entry3]);

        $chain = new ChainCatalog($catalog1, $catalog2);

        $all = $chain->all('en');

        self::assertCount(2, $all);
        self::assertSame('First', $all['welcome']->message);
        self::assertSame('Bye', $all['goodbye']->message);
    }
}
