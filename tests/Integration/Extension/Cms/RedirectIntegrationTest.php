<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Seo\RedirectManager;

use function array_slice;
use function array_values;

#[CoversClass(RedirectManager::class)]
final class RedirectIntegrationTest extends TestCase
{
    private InMemoryRedirectRepository $repo;
    private RedirectManager $manager;

    protected function setUp(): void
    {
        $this->repo = new InMemoryRedirectRepository();
        $this->manager = new RedirectManager($this->repo, new NullLogger());
    }

    // -- Redirect resolution --------------------------------------------------

    #[Test]
    public function test_redirect_resolution_returns_301(): void
    {
        $redirect = $this->manager->create(
            fromPath: '/old-page',
            toPath: '/new-page',
            statusCode: 301,
            createdBy: 'user-001',
            reason: 'Page moved',
        );

        $resolved = $this->manager->resolve('/old-page');

        self::assertNotNull($resolved);
        self::assertSame('/new-page', $resolved->toPath);
        self::assertSame(301, $resolved->statusCode);
    }

    #[Test]
    public function test_redirect_resolution_returns_308(): void
    {
        $this->manager->create(
            fromPath: '/api/v1',
            toPath: '/api/v2',
            statusCode: 308,
            createdBy: 'user-001',
            reason: 'API upgrade',
        );

        $resolved = $this->manager->resolve('/api/v1');

        self::assertNotNull($resolved);
        self::assertSame(308, $resolved->statusCode);
    }

    // -- Chain collapse -------------------------------------------------------

    #[Test]
    public function test_chain_collapse_follows_full_chain(): void
    {
        $this->manager->create('/step-1', '/step-2', 301, 'user-001', 'First move');
        $this->manager->create('/step-2', '/step-3', 301, 'user-001', 'Second move');
        $this->manager->create('/step-3', '/final', 301, 'user-001', 'Third move');

        $resolved = $this->manager->resolve('/step-1');

        self::assertNotNull($resolved);
        self::assertSame('/final', $resolved->toPath);
        self::assertSame('/step-1', $resolved->fromPath);
    }

    #[Test]
    public function test_chain_collapse_preserves_original_status_code(): void
    {
        $this->manager->create('/old', '/mid', 308, 'user-001', 'Move A');
        $this->manager->create('/mid', '/new', 301, 'user-001', 'Move B');

        $resolved = $this->manager->resolve('/old');

        self::assertNotNull($resolved);
        self::assertSame('/new', $resolved->toPath);
        self::assertSame(308, $resolved->statusCode);
    }

    // -- Slug change creates redirect -----------------------------------------

    #[Test]
    public function test_slug_change_creates_redirect_with_persisted_record(): void
    {
        $redirect = $this->manager->create(
            fromPath: '/blog/old-slug',
            toPath: '/blog/new-slug',
            statusCode: 301,
            createdBy: 'user-001',
            reason: 'Slug renamed',
        );

        // Verify it's persisted
        $found = $this->manager->resolve('/blog/old-slug');

        self::assertNotNull($found);
        self::assertSame('/blog/new-slug', $found->toPath);
        self::assertSame(301, $found->statusCode);
    }

    #[Test]
    public function test_multiple_slug_changes_chain_collapse(): void
    {
        // Simulate: article slug changed twice: old -> mid -> new
        $this->manager->create('/articles/old-title', '/articles/mid-title', 301, 'user-001', 'Slug change 1');
        $this->manager->create('/articles/mid-title', '/articles/new-title', 301, 'user-001', 'Slug change 2');

        $resolved = $this->manager->resolve('/articles/old-title');

        self::assertNotNull($resolved);
        self::assertSame('/articles/new-title', $resolved->toPath);
    }

    // -- Bulk CSV import ------------------------------------------------------

    #[Test]
    public function test_bulk_csv_import_creates_multiple_redirects(): void
    {
        $csv = "/old-1,/new-1,301\n/old-2,/new-2,308\n/old-3,/new-3,301";

        $result = $this->manager->importCsv($csv, 'user-001', 'Bulk migration');

        self::assertSame(3, $result['imported']);
        self::assertSame(0, $result['skipped']);

        self::assertNotNull($this->manager->resolve('/old-1'));
        self::assertNotNull($this->manager->resolve('/old-2'));
        self::assertNotNull($this->manager->resolve('/old-3'));
    }

    #[Test]
    public function test_bulk_csv_import_mixed_valid_and_invalid(): void
    {
        $csv = "/valid,/new-valid,301\n/evil,javascript:alert(1),301\n/also-valid,/new-also,308";

        $result = $this->manager->importCsv($csv, 'user-001', 'Bulk migration');

        self::assertSame(2, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(1, $result['errors']);

        self::assertNotNull($this->manager->resolve('/valid'));
        self::assertNotNull($this->manager->resolve('/also-valid'));
    }

    // -- Open redirect protection in integration context ----------------------

    #[Test]
    public function test_open_redirect_protection_blocks_unsafe_in_create(): void
    {
        $this->expectException(CmsException::class);
        $this->manager->create('/old', '//evil.com', 301, 'user-001', 'test');
    }

    // -- Resolve returns null for nonexistent path ----------------------------

    #[Test]
    public function test_resolve_nonexistent_returns_null(): void
    {
        self::assertNull($this->manager->resolve('/nonexistent'));
    }

    // -- Hit tracking ---------------------------------------------------------

    #[Test]
    public function test_resolve_increments_hit_counter(): void
    {
        $redirect = $this->manager->create('/tracked', '/dest', 301, 'user-001', 'test');

        $this->manager->resolve('/tracked');
        $this->manager->resolve('/tracked');

        self::assertSame(2, $this->repo->getHitCount($redirect->id));
    }

    // -- Delete ---------------------------------------------------------------

    #[Test]
    public function test_delete_removes_redirect(): void
    {
        $redirect = $this->manager->create('/deletable', '/target', 301, 'user-001', 'test');

        $this->manager->delete($redirect->id);

        self::assertNull($this->manager->resolve('/deletable'));
    }
}

// -- In-memory redirect repository for integration tests ----------------------

final class InMemoryRedirectRepository implements RedirectRepositoryInterface
{
    /** @var array<string, Redirect> */
    private array $redirects = [];

    /** @var array<string, int> */
    private array $hitCounts = [];

    public function findByPath(string $path, ?string $locale = null, ?string $tenantId = null): ?Redirect
    {
        foreach ($this->redirects as $redirect) {
            if ($redirect->fromPath === $path) {
                return $redirect;
            }
        }

        return null;
    }

    public function save(Redirect $redirect): void
    {
        $this->redirects[$redirect->id] = $redirect;
    }

    public function incrementHits(string $redirectId): void
    {
        $this->hitCounts[$redirectId] = ($this->hitCounts[$redirectId] ?? 0) + 1;
    }

    public function findAll(int $page = 1, int $perPage = 50, ?string $tenantId = null): array
    {
        $items = array_values($this->redirects);
        $offset = ($page - 1) * $perPage;

        return array_slice($items, $offset, $perPage);
    }

    public function delete(string $redirectId): void
    {
        unset($this->redirects[$redirectId]);
    }

    public function getHitCount(string $redirectId): int
    {
        return $this->hitCounts[$redirectId] ?? 0;
    }
}
