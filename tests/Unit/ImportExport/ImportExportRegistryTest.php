<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ImportExport;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\ImportExport\ExportRequest;
use Pulsar\ImportExport\ExportResult;
use Pulsar\ImportExport\ImportExportProviderInterface;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\ImportExport\ImportRequest;
use Pulsar\ImportExport\ImportResult;

#[CoversClass(ImportExportRegistry::class)]
final class ImportExportRegistryTest extends TestCase
{
    private ImportExportRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ImportExportRegistry();
    }

    #[Test]
    public function registersProvider(): void
    {
        $provider = $this->createProviderStub('cms');
        $this->registry->register($provider);

        self::assertTrue($this->registry->has('cms'));
        self::assertSame($provider, $this->registry->getProvider('cms'));
    }

    #[Test]
    public function rejectsDuplicateProviderName(): void
    {
        $this->registry->register($this->createProviderStub('cms'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        $this->registry->register($this->createProviderStub('cms'));
    }

    #[Test]
    public function returnsNullForUnregisteredProvider(): void
    {
        self::assertNull($this->registry->getProvider('nonexistent'));
    }

    #[Test]
    public function hasReturnsFalseForUnregistered(): void
    {
        self::assertFalse($this->registry->has('nonexistent'));
    }

    #[Test]
    public function getProvidersReturnsAllRegistered(): void
    {
        $cms = $this->createProviderStub('cms');
        $forum = $this->createProviderStub('forum');

        $this->registry->register($cms);
        $this->registry->register($forum);

        $providers = $this->registry->getProviders();

        self::assertCount(2, $providers);
        self::assertSame($cms, $providers[0]);
        self::assertSame($forum, $providers[1]);
    }

    #[Test]
    public function getProviderNamesReturnsAllNames(): void
    {
        $this->registry->register($this->createProviderStub('cms'));
        $this->registry->register($this->createProviderStub('forum'));

        self::assertSame(['cms', 'forum'], $this->registry->getProviderNames());
    }

    #[Test]
    public function exportAllExportsFromAllProviders(): void
    {
        $cmsResult = new ExportResult('cms', ['content' => []], 'json', 'hash1', ['content']);
        $forumResult = new ExportResult('forum', ['tags' => []], 'json', 'hash2', ['tags']);

        $cms = $this->createProviderStub('cms');
        $cms->method('export')->willReturn($cmsResult);

        $forum = $this->createProviderStub('forum');
        $forum->method('export')->willReturn($forumResult);

        $this->registry->register($cms);
        $this->registry->register($forum);

        $request = new ExportRequest();
        $results = $this->registry->exportAll($request);

        self::assertCount(2, $results);
        self::assertSame('cms', $results[0]->providerName);
        self::assertSame('forum', $results[1]->providerName);
    }

    #[Test]
    public function exportAllFiltersToRequestedProviders(): void
    {
        $cmsResult = new ExportResult('cms', [], 'json', 'hash1', []);

        $cms = $this->createProviderStub('cms');
        $cms->method('export')->willReturn($cmsResult);

        $forum = $this->createProviderStub('forum');

        $this->registry->register($cms);
        $this->registry->register($forum);

        $results = $this->registry->exportAll(new ExportRequest(), ['cms']);

        self::assertCount(1, $results);
        self::assertSame('cms', $results[0]->providerName);
    }

    #[Test]
    public function exportAllSkipsUnknownProviderNames(): void
    {
        $cms = $this->createProviderStub('cms');
        $cms->method('export')->willReturn(new ExportResult('cms', [], 'json', 'h', []));
        $this->registry->register($cms);

        $results = $this->registry->exportAll(new ExportRequest(), ['cms', 'nonexistent']);

        self::assertCount(1, $results);
    }

    #[Test]
    public function importToRoutesToCorrectProvider(): void
    {
        $expected = new ImportResult('forum', ['tags' => 3], [], [], [], [], false);

        $forum = $this->createProviderStub('forum');
        $forum->method('import')->willReturn($expected);

        $this->registry->register($forum);

        $request = new ImportRequest(content: '{"tags":[]}', dryRun: false);
        $result = $this->registry->importTo('forum', $request);

        self::assertSame('forum', $result->providerName);
        self::assertSame(3, $result->totalCreated());
    }

    #[Test]
    public function importToThrowsForUnknownProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not registered');

        $this->registry->importTo('nonexistent', new ImportRequest(content: '{}'));
    }

    #[Test]
    public function emptyRegistryReturnsEmptyLists(): void
    {
        self::assertSame([], $this->registry->getProviders());
        self::assertSame([], $this->registry->getProviderNames());
        self::assertSame([], $this->registry->exportAll(new ExportRequest()));
    }

    /**
     * @return ImportExportProviderInterface&Stub
     */
    private function createProviderStub(string $name): ImportExportProviderInterface&Stub
    {
        $stub = $this->createStub(ImportExportProviderInterface::class);
        $stub->method('name')->willReturn($name);

        return $stub;
    }
}
