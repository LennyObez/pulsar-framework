<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGenerator;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuItem;
use Pulsar\Extension\Cms\Navigation\MenuItemTranslation;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Navigation\MenuTranslation;

use function assert;
use function is_string;

#[CoversClass(Menu::class)]
#[CoversClass(MenuItem::class)]
#[CoversClass(BreadcrumbGenerator::class)]
final class MenuTest extends TestCase
{
    private InMemoryMenuRepository $menuRepo;

    protected function setUp(): void
    {
        $this->menuRepo = new InMemoryMenuRepository();
    }

    #[Test]
    public function createMenuWithItems(): void
    {
        $menu = new Menu(
            id: 'menu-001',
            tenantId: null,
            location: 'primary',
            createdAt: new DateTimeImmutable(),
        );

        $this->menuRepo->save($menu, [
            new MenuTranslation('menu-001', 'en', 'Main Navigation'),
        ]);

        $item1 = new MenuItem(
            id: 'item-001',
            menuId: 'menu-001',
            parentId: null,
            contentId: 'content-001',
            url: null,
            target: LinkTarget::Self,
            cssClass: null,
            icon: null,
            sortOrder: 0,
            visible: true,
        );

        $item2 = new MenuItem(
            id: 'item-002',
            menuId: 'menu-001',
            parentId: null,
            contentId: null,
            url: 'https://external.example.com',
            target: LinkTarget::Blank,
            cssClass: 'external-link',
            icon: 'external',
            sortOrder: 1,
            visible: true,
        );

        $this->menuRepo->saveItem($item1, [
            new MenuItemTranslation('item-001', 'en', 'Home', 'Go to home'),
        ]);
        $this->menuRepo->saveItem($item2, [
            new MenuItemTranslation('item-002', 'en', 'Partner Site', null),
        ]);

        $found = $this->menuRepo->findByLocation('primary', 'en');
        self::assertNotNull($found);
        self::assertSame('primary', $found->location);

        $items = $this->menuRepo->getItems('menu-001');
        self::assertCount(2, $items);
        self::assertSame('item-001', $items[0]->id);
        self::assertSame(LinkTarget::Self, $items[0]->target);
        self::assertSame('item-002', $items[1]->id);
        self::assertSame(LinkTarget::Blank, $items[1]->target);
    }

    #[Test]
    public function menuTreeStructureAssembly(): void
    {
        $menu = new Menu(
            id: 'menu-002',
            tenantId: null,
            location: 'sidebar',
            createdAt: new DateTimeImmutable(),
        );
        $this->menuRepo->save($menu, [
            new MenuTranslation('menu-002', 'en', 'Sidebar'),
        ]);

        // Root item
        $root = new MenuItem(
            id: 'item-root',
            menuId: 'menu-002',
            parentId: null,
            contentId: null,
            url: '/about',
            target: LinkTarget::Self,
            cssClass: null,
            icon: null,
            sortOrder: 0,
            visible: true,
        );
        $this->menuRepo->saveItem($root, [
            new MenuItemTranslation('item-root', 'en', 'About', null),
        ]);

        // Child items
        $child1 = new MenuItem(
            id: 'item-child-1',
            menuId: 'menu-002',
            parentId: 'item-root',
            contentId: null,
            url: '/about/team',
            target: LinkTarget::Self,
            cssClass: null,
            icon: null,
            sortOrder: 0,
            visible: true,
        );
        $this->menuRepo->saveItem($child1, [
            new MenuItemTranslation('item-child-1', 'en', 'Our Team', null),
        ]);

        $child2 = new MenuItem(
            id: 'item-child-2',
            menuId: 'menu-002',
            parentId: 'item-root',
            contentId: null,
            url: '/about/history',
            target: LinkTarget::Self,
            cssClass: null,
            icon: null,
            sortOrder: 1,
            visible: true,
        );
        $this->menuRepo->saveItem($child2, [
            new MenuItemTranslation('item-child-2', 'en', 'Our History', null),
        ]);

        // Build tree
        $allItems = $this->menuRepo->getItems('menu-002');
        self::assertCount(3, $allItems);

        $rootItems = array_filter($allItems, static fn(MenuItem $i) => $i->parentId === null);
        self::assertCount(1, $rootItems);

        $children = array_filter($allItems, static fn(MenuItem $i) => $i->parentId === 'item-root');
        self::assertCount(2, $children);
    }

    #[Test]
    public function breadcrumbGenerationForNestedContent(): void
    {
        // Create a 3-level content hierarchy: Home > Docs > Getting Started
        $contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $translationRepo = $this->createStub(ContentTranslationRepositoryInterface::class);
        $config = new CmsConfig(
            defaultLocale: 'en',
            defaultLocaleInUrl: false,
        );

        $home = Content::create(
            id: 'home-001',
            contentType: ContentType::Page,
            authorId: 'author-001',
        );
        $docs = Content::create(
            id: 'docs-001',
            contentType: ContentType::Page,
            authorId: 'author-001',
            parentId: 'home-001',
        );
        $gettingStarted = Content::create(
            id: 'gs-001',
            contentType: ContentType::Page,
            authorId: 'author-001',
            parentId: 'docs-001',
        );

        $contentRepo->method('findById')->willReturnMap([
            ['home-001', $home],
            ['docs-001', $docs],
            ['gs-001', $gettingStarted],
        ]);

        // BreadcrumbGenerator calls findAncestors() to walk up the hierarchy
        $contentRepo->method('findAncestors')->willReturn([$docs, $home]);

        $translationRepo->method('findByContentIds')->willReturnCallback(
            static function (array $contentIds): array {
                /** @var array<string, list<ContentTranslation>> $all */
                $all = [
                    'home-001' => [new ContentTranslation(
                        id: 'trans-home',
                        contentId: 'home-001',
                        locale: 'en',
                        title: 'Home',
                        slugSegment: 'home',
                        path: 'home',
                        body: '',
                        excerpt: null,
                        metaTitle: null,
                        metaDescription: null,
                        ogImageId: null,
                        robots: null,
                        structuredDataOverrides: null,
                        readingTimeMinutes: null,
                        bodyPlaintext: '',
                        headingsText: '',
                        customFieldsText: '',
                        taxonomyTermsText: '',
                    )],
                    'docs-001' => [new ContentTranslation(
                        id: 'trans-docs',
                        contentId: 'docs-001',
                        locale: 'en',
                        title: 'Documentation',
                        slugSegment: 'docs',
                        path: 'home/docs',
                        body: '',
                        excerpt: null,
                        metaTitle: null,
                        metaDescription: null,
                        ogImageId: null,
                        robots: null,
                        structuredDataOverrides: null,
                        readingTimeMinutes: null,
                        bodyPlaintext: '',
                        headingsText: '',
                        customFieldsText: '',
                        taxonomyTermsText: '',
                    )],
                    'gs-001' => [new ContentTranslation(
                        id: 'trans-gs',
                        contentId: 'gs-001',
                        locale: 'en',
                        title: 'Getting Started',
                        slugSegment: 'getting-started',
                        path: 'home/docs/getting-started',
                        body: '',
                        excerpt: null,
                        metaTitle: null,
                        metaDescription: null,
                        ogImageId: null,
                        robots: null,
                        structuredDataOverrides: null,
                        readingTimeMinutes: null,
                        bodyPlaintext: '',
                        headingsText: '',
                        customFieldsText: '',
                        taxonomyTermsText: '',
                    )],
                ];

                $result = [];

                foreach ($contentIds as $id) {
                    assert(is_string($id));

                    if (isset($all[$id])) {
                        $result[$id] = $all[$id];
                    }
                }

                return $result;
            },
        );

        $generator = new BreadcrumbGenerator($contentRepo, $translationRepo, $config);
        $breadcrumbs = $generator->generate($gettingStarted, 'en');

        self::assertCount(3, $breadcrumbs);

        // Root-first order
        self::assertSame('Home', $breadcrumbs[0]->label);
        self::assertSame('/home', $breadcrumbs[0]->url);
        self::assertFalse($breadcrumbs[0]->isCurrent);

        self::assertSame('Documentation', $breadcrumbs[1]->label);
        self::assertSame('/home/docs', $breadcrumbs[1]->url);
        self::assertFalse($breadcrumbs[1]->isCurrent);

        self::assertSame('Getting Started', $breadcrumbs[2]->label);
        self::assertSame('/home/docs/getting-started', $breadcrumbs[2]->url);
        self::assertTrue($breadcrumbs[2]->isCurrent);
    }
}

final class InMemoryMenuRepository implements MenuRepositoryInterface
{
    /** @var array<string, Menu> */
    private array $menus = [];

    /** @var array<string, MenuItem> */
    private array $items = [];

    public function findByImportId(string $importId): ?Menu
    {
        return null;
    }

    public function findItemByImportId(string $importId): ?MenuItem
    {
        return null;
    }

    public function findByLocation(string $location, string $locale, ?string $tenantId = null): ?Menu
    {
        foreach ($this->menus as $menu) {
            if ($menu->location === $location && $menu->tenantId === $tenantId) {
                return $menu;
            }
        }

        return null;
    }

    public function save(Menu $menu, array $translations): void
    {
        $this->menus[$menu->id] = $menu;
    }

    public function saveItem(MenuItem $item, array $translations): void
    {
        $this->items[$item->id] = $item;
    }

    /** @return list<MenuItem> */
    public function getItems(string $menuId): array
    {
        $matching = array_filter(
            $this->items,
            static fn(MenuItem $i) => $i->menuId === $menuId,
        );

        $matching = array_values($matching);
        usort($matching, static fn(MenuItem $a, MenuItem $b) => $a->sortOrder <=> $b->sortOrder);

        return $matching;
    }
}
