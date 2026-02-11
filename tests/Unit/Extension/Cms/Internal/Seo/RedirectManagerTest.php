<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal\Seo;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Seo\RedirectManager;

#[CoversClass(RedirectManager::class)]
final class RedirectManagerTest extends TestCase
{
    private RedirectRepositoryInterface & \PHPUnit\Framework\MockObject\Stub $repo;
    private RedirectManager $manager;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(RedirectRepositoryInterface::class);
        $this->manager = new RedirectManager($this->repo, new NullLogger());
    }

    #[Test]
    public function resolveReturnsNullWhenNoMatch(): void
    {
        $this->repo->method('findByPath')->willReturn(null);

        self::assertNull($this->manager->resolve('/nonexistent'));
    }

    #[Test]
    public function resolveReturnsSingleRedirect(): void
    {
        $redirect = $this->buildRedirect('/old', '/new');

        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->method('findByPath')->willReturnCallback(
            fn(string $path): ?Redirect => $path === '/old' ? $redirect : null,
        );
        $repo->expects(self::once())->method('incrementHits')->with($redirect->id);

        $manager = new RedirectManager($repo, new NullLogger());
        $result = $manager->resolve('/old');

        self::assertNotNull($result);
        self::assertSame('/old', $result->fromPath);
        self::assertSame('/new', $result->toPath);
    }

    #[Test]
    public function resolveFollowsChainToFinalDestination(): void
    {
        $redirect1 = $this->buildRedirect('/a', '/b');
        $redirect2 = $this->buildRedirect('/b', '/c');

        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->method('findByPath')->willReturnCallback(
            fn(string $path): ?Redirect => match ($path) {
                '/a' => $redirect1,
                '/b' => $redirect2,
                default => null,
            },
        );
        $repo->expects(self::once())->method('incrementHits');

        $manager = new RedirectManager($repo, new NullLogger());
        $result = $manager->resolve('/a');

        self::assertNotNull($result);
        self::assertSame('/a', $result->fromPath);
        self::assertSame('/c', $result->toPath);
    }

    #[Test]
    public function resolveDetectsCycleAndStops(): void
    {
        $redirect1 = $this->buildRedirect('/a', '/b');
        $redirect2 = $this->buildRedirect('/b', '/a');

        $repo = $this->createStub(RedirectRepositoryInterface::class);
        $repo->method('findByPath')->willReturnCallback(
            fn(string $path): ?Redirect => match ($path) {
                '/a' => $redirect1,
                '/b' => $redirect2,
                default => null,
            },
        );

        $manager = new RedirectManager($repo, new NullLogger());
        $result = $manager->resolve('/a');

        self::assertNotNull($result);
        // Should stop at /b since /a is already visited
        self::assertSame('/b', $result->toPath);
    }

    #[Test]
    public function createSavesRedirectWithValidTarget(): void
    {
        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $manager = new RedirectManager($repo, new NullLogger());
        $redirect = $manager->create('/old', '/new', 301, 'user-1', 'Slug changed');

        self::assertSame('/old', $redirect->fromPath);
        self::assertSame('/new', $redirect->toPath);
        self::assertSame(301, $redirect->statusCode);
        self::assertSame(0, $redirect->hits);
    }

    #[Test]
    public function createNormalizesInvalidStatusCodeTo301(): void
    {
        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $manager = new RedirectManager($repo, new NullLogger());
        $redirect = $manager->create('/old', '/new', 302, 'user-1', 'Test');

        self::assertSame(301, $redirect->statusCode);
    }

    #[Test]
    public function createAccepts308StatusCode(): void
    {
        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $manager = new RedirectManager($repo, new NullLogger());
        $redirect = $manager->create('/old', '/new', 308, 'user-1', 'Test');

        self::assertSame(308, $redirect->statusCode);
    }

    #[Test]
    #[DataProvider('dangerousUrlProvider')]
    public function createBlocksDangerousTargetUrls(string $url): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Open redirect blocked');

        $this->manager->create('/old', $url, 301, 'user-1', 'Test');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousUrlProvider(): iterable
    {
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>alert(1)</script>'];
        yield 'vbscript scheme' => ['vbscript:MsgBox'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'ftp scheme' => ['ftp://evil.com/malware'];
        yield 'protocol-relative' => ['//evil.com'];
        yield 'null byte' => ["/safe\0/evil"];
        yield 'encoded javascript' => ['%6A%61%76%61%73%63%72%69%70%74:alert(1)'];
        yield 'custom scheme' => ['custom:payload'];
    }

    #[Test]
    public function createAllowsHttpsUrl(): void
    {
        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $manager = new RedirectManager($repo, new NullLogger());
        $redirect = $manager->create('/old', 'https://example.com/page', 301, 'user-1', 'External redirect');

        self::assertSame('https://example.com/page', $redirect->toPath);
    }

    #[Test]
    public function createAllowsRelativePath(): void
    {
        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $manager = new RedirectManager($repo, new NullLogger());
        $redirect = $manager->create('/old', '/new/page', 301, 'user-1', 'Internal redirect');

        self::assertSame('/new/page', $redirect->toPath);
    }

    #[Test]
    public function deleteDelegatesToRepository(): void
    {
        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->expects(self::once())->method('delete')->with('redirect-1');

        $manager = new RedirectManager($repo, new NullLogger());
        $manager->delete('redirect-1');
    }

    #[Test]
    public function importCsvImportsValidLines(): void
    {
        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('save');

        $manager = new RedirectManager($repo, new NullLogger());
        $csv = "/old-1,/new-1,301\n/old-2,/new-2,308";

        $result = $manager->importCsv($csv, 'admin', 'Bulk import');

        self::assertSame(2, $result['imported']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);
    }

    #[Test]
    public function importCsvSkipsHeaderRow(): void
    {
        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $manager = new RedirectManager($repo, new NullLogger());
        $csv = "from_path,to_path,status_code\n/old,/new,301";

        $result = $manager->importCsv($csv, 'admin', 'Import');

        self::assertSame(1, $result['imported']);
    }

    #[Test]
    public function importCsvSkipsEmptyAndCommentLines(): void
    {
        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $manager = new RedirectManager($repo, new NullLogger());
        $csv = "# This is a comment\n\n/old,/new,301\n\n# Another comment";

        $result = $manager->importCsv($csv, 'admin', 'Import');

        self::assertSame(1, $result['imported']);
    }

    #[Test]
    public function importCsvReportsInsufficientColumns(): void
    {
        $repo = $this->createStub(RedirectRepositoryInterface::class);
        $manager = new RedirectManager($repo, new NullLogger());

        $result = $manager->importCsv('only-one-column', 'admin', 'Import');

        self::assertSame(0, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('insufficient columns', $result['errors'][0]);
    }

    #[Test]
    public function importCsvReportsEmptyPaths(): void
    {
        $repo = $this->createStub(RedirectRepositoryInterface::class);
        $manager = new RedirectManager($repo, new NullLogger());

        $result = $manager->importCsv(',/new', 'admin', 'Import');

        self::assertSame(0, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertStringContainsString('empty from_path', $result['errors'][0]);
    }

    #[Test]
    public function importCsvReportsValidationErrors(): void
    {
        $repo = $this->createStub(RedirectRepositoryInterface::class);
        $manager = new RedirectManager($repo, new NullLogger());

        $result = $manager->importCsv('/old,javascript:alert(1),301', 'admin', 'Import');

        self::assertSame(0, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(1, $result['errors']);
    }

    #[Test]
    public function importCsvDefaultsTo301WhenStatusCodeMissing(): void
    {
        $repo = $this->createMock(RedirectRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $manager = new RedirectManager($repo, new NullLogger());
        $result = $manager->importCsv('/old,/new', 'admin', 'Import');

        self::assertSame(1, $result['imported']);
    }

    #[Test]
    public function listAllDelegatesToRepository(): void
    {
        $redirects = [$this->buildRedirect('/a', '/b')];
        $this->repo->method('findAll')->willReturn($redirects);

        $result = $this->manager->listAll(1, 50);

        self::assertSame($redirects, $result);
    }

    private function buildRedirect(string $from, string $to, int $status = 301): Redirect
    {
        return new Redirect(
            id: 'redirect-' . md5($from),
            tenantId: null,
            fromPath: $from,
            toPath: $to,
            statusCode: $status,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: new DateTimeImmutable(),
            createdBy: 'user-1',
            reason: 'Test redirect',
        );
    }
}
