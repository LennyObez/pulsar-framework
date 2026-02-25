<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Seo;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Seo\RedirectManager;

#[CoversClass(RedirectManager::class)]
final class RedirectTest extends TestCase
{
    // -- Chain collapse -------------------------------------------------------

    #[Test]
    public function chainCollapseAToBToCResolvesToC(): void
    {
        $now = new DateTimeImmutable();
        $redirectAB = $this->createRedirect('r1', '/old', '/middle', createdAt: $now);
        $redirectBC = $this->createRedirect('r2', '/middle', '/final', createdAt: $now);

        $repo = $this->createRepository([
            '/old' => $redirectAB,
            '/middle' => $redirectBC,
        ]);

        $manager = new RedirectManager($repo, new NullLogger());
        $result = $manager->resolve('/old');

        self::assertNotNull($result);
        self::assertSame('/final', $result->toPath);
        self::assertSame('/old', $result->fromPath);
    }

    #[Test]
    public function resolveReturnsNullWhenNoRedirectExists(): void
    {
        $repo = $this->createRepository([]);
        $manager = new RedirectManager($repo, new NullLogger());

        self::assertNull($manager->resolve('/nonexistent'));
    }

    #[Test]
    public function resolveReturnsDirectRedirectWhenNoChain(): void
    {
        $now = new DateTimeImmutable();
        $redirect = $this->createRedirect('r1', '/old', '/new', createdAt: $now);
        $repo = $this->createRepository(['/old' => $redirect]);

        $manager = new RedirectManager($repo, new NullLogger());
        $result = $manager->resolve('/old');

        self::assertNotNull($result);
        self::assertSame('/new', $result->toPath);
    }

    // -- Create from slug change ----------------------------------------------

    #[Test]
    public function createRedirectFromSlugChange(): void
    {
        $repo = $this->createSavingRepository();
        $manager = new RedirectManager($repo, new NullLogger());

        $redirect = $manager->create(
            fromPath: '/old-slug',
            toPath: '/new-slug',
            statusCode: 301,
            createdBy: 'user-001',
            reason: 'Slug changed',
        );

        self::assertSame('/old-slug', $redirect->fromPath);
        self::assertSame('/new-slug', $redirect->toPath);
        self::assertSame(301, $redirect->statusCode);
        self::assertSame('Slug changed', $redirect->reason);
    }

    // -- Open redirect protection: REJECTS ------------------------------------

    #[Test]
    public function rejectsJavascriptScheme(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $this->expectException(CmsException::class);
        $manager->create('/old', 'javascript:alert(1)', 301, 'user-001', 'test');
    }

    #[Test]
    public function rejectsDataScheme(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $this->expectException(CmsException::class);
        $manager->create('/old', 'data:text/html,<script>alert(1)</script>', 301, 'user-001', 'test');
    }

    #[Test]
    public function rejectsVbscriptScheme(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $this->expectException(CmsException::class);
        $manager->create('/old', 'vbscript:code', 301, 'user-001', 'test');
    }

    #[Test]
    public function rejectsProtocolRelativeUrl(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $this->expectException(CmsException::class);
        $manager->create('/old', '//evil.com/phish', 301, 'user-001', 'test');
    }

    #[Test]
    public function rejectsJavascriptCaseInsensitive(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $this->expectException(CmsException::class);
        $manager->create('/old', 'JaVaScRiPt:alert(1)', 301, 'user-001', 'test');
    }

    #[Test]
    public function rejectsUrlEncodedJavascript(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $this->expectException(CmsException::class);
        $manager->create('/old', 'java%73cript:alert(1)', 301, 'user-001', 'test');
    }

    // -- Open redirect protection: ALLOWS -------------------------------------

    #[Test]
    public function allowsInternalPath(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $redirect = $manager->create('/old', '/internal/path', 301, 'user-001', 'test');

        self::assertSame('/internal/path', $redirect->toPath);
    }

    #[Test]
    public function allowsHttpsUrl(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $redirect = $manager->create('/old', 'https://same-domain.com/path', 301, 'user-001', 'test');

        self::assertSame('https://same-domain.com/path', $redirect->toPath);
    }

    #[Test]
    public function allowsHttpUrl(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $redirect = $manager->create('/old', 'http://example.com/page', 301, 'user-001', 'test');

        self::assertSame('http://example.com/page', $redirect->toPath);
    }

    // -- Status code validation -----------------------------------------------

    #[Test]
    public function statusCode301Accepted(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $redirect = $manager->create('/old', '/new', 301, 'user-001', 'test');

        self::assertSame(301, $redirect->statusCode);
    }

    #[Test]
    public function statusCode308Accepted(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $redirect = $manager->create('/old', '/new', 308, 'user-001', 'test');

        self::assertSame(308, $redirect->statusCode);
    }

    #[Test]
    public function invalidStatusCodeDefaultsTo301(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $redirect = $manager->create('/old', '/new', 302, 'user-001', 'test');

        self::assertSame(301, $redirect->statusCode);
    }

    // -- CSV import -----------------------------------------------------------

    #[Test]
    public function csvImportParsesValidCsv(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $csv = "/old1,/new1,301\n/old2,/new2,308";
        $result = $manager->importCsv($csv, 'user-001', 'Bulk import');

        self::assertSame(2, $result['imported']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);
    }

    #[Test]
    public function csvImportSkipsHeaderRow(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $csv = "from_path,to_path,status_code\n/old,/new,301";
        $result = $manager->importCsv($csv, 'user-001', 'Bulk import');

        self::assertSame(1, $result['imported']);
    }

    #[Test]
    public function csvImportSkipsEmptyLinesAndComments(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $csv = "/old,/new,301\n\n# This is a comment\n/old2,/new2,301";
        $result = $manager->importCsv($csv, 'user-001', 'Bulk import');

        self::assertSame(2, $result['imported']);
    }

    #[Test]
    public function csvImportReportsErrorsForInsufficientColumns(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $csv = '/only-one-column';
        $result = $manager->importCsv($csv, 'user-001', 'Bulk import');

        self::assertSame(0, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(1, $result['errors']);
    }

    #[Test]
    public function csvImportReportsErrorsForUnsafeUrls(): void
    {
        $manager = new RedirectManager($this->createSavingRepository(), new NullLogger());

        $csv = '/old,javascript:alert(1),301';
        $result = $manager->importCsv($csv, 'user-001', 'Bulk import');

        self::assertSame(0, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(1, $result['errors']);
    }

    #[Test]
    public function csvImportDefaultsStatusCodeTo301(): void
    {
        $repo = $this->createSavingRepository();
        $manager = new RedirectManager($repo, new NullLogger());

        $csv = '/old,/new';
        $result = $manager->importCsv($csv, 'user-001', 'Bulk import');

        self::assertSame(1, $result['imported']);
        self::assertNotNull($repo->lastSaved);
        self::assertSame(301, $repo->lastSaved->statusCode);
    }

    // -- Cycle detection ------------------------------------------------------

    #[Test]
    public function redirectCycleDoesNotInfiniteLoop(): void
    {
        $now = new DateTimeImmutable();
        $redirectAB = $this->createRedirect('r1', '/a', '/b', createdAt: $now);
        $redirectBA = $this->createRedirect('r2', '/b', '/a', createdAt: $now);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $repo = $this->createRepository([
            '/a' => $redirectAB,
            '/b' => $redirectBA,
        ]);

        $manager = new RedirectManager($repo, $logger);
        $result = $manager->resolve('/a');

        // Should resolve without infinite loop, cycle detected
        self::assertNotNull($result);
    }

    // -- Helpers --------------------------------------------------------------

    private function createRedirect(
        string $id,
        string $fromPath,
        string $toPath,
        int $statusCode = 301,
        ?DateTimeImmutable $createdAt = null,
    ): Redirect {
        return new Redirect(
            id: $id,
            tenantId: null,
            fromPath: $fromPath,
            toPath: $toPath,
            statusCode: $statusCode,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $createdAt ?? new DateTimeImmutable(),
            createdBy: 'system',
            reason: 'test',
        );
    }

    /**
     * @param array<string, Redirect> $pathMap
     */
    private function createRepository(array $pathMap): RedirectRepositoryInterface
    {
        return new class ($pathMap) implements RedirectRepositoryInterface {
            /** @param array<string, Redirect> $map */
            public function __construct(private readonly array $map) {}

            public function findByPath(string $path, ?string $locale = null, ?string $tenantId = null): ?Redirect
            {
                return $this->map[$path] ?? null;
            }

            public function save(Redirect $redirect): void {}

            public function incrementHits(string $redirectId): void {}

            public function findAll(int $page = 1, int $perPage = 50, ?string $tenantId = null): array
            {
                return [];
            }

            public function delete(string $redirectId): void {}

            public function purgeDeleted(DateTimeImmutable $olderThan): int
            {
                return 0;
            }
        };
    }

    private function createSavingRepository(): RedirectRepositoryInterface&SaveAwareRedirectRepository
    {
        return new class implements RedirectRepositoryInterface, SaveAwareRedirectRepository {
            public ?Redirect $lastSaved = null;

            public function findByPath(string $path, ?string $locale = null, ?string $tenantId = null): ?Redirect
            {
                return null;
            }

            public function save(Redirect $redirect): void
            {
                $this->lastSaved = $redirect;
            }

            public function incrementHits(string $redirectId): void {}

            public function findAll(int $page = 1, int $perPage = 50, ?string $tenantId = null): array
            {
                return [];
            }

            public function delete(string $redirectId): void {}

            public function purgeDeleted(DateTimeImmutable $olderThan): int
            {
                return 0;
            }
        };
    }
}

/**
 * @internal Test-only interface for type intersection
 */
interface SaveAwareRedirectRepository
{
    public ?Redirect $lastSaved { get; }
}
