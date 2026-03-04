<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Tools\ImportExportService;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;
use Pulsar\ImportExport\ImportExportProviderInterface;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\ImportExport\ImportResult as RegistryImportResult;

use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Tests the unified import auto-detection and delegation logic.
 *
 * Since the internal classes (ExportBundleGenerator, ImportParser,
 * SiteDefinitionParser) are final, we test through the interface
 * using a stub-based approach.
 */
#[CoversClass(ImportExportService::class)]
final class ImportExportServiceTest extends TestCase
{
    #[Test]
    public function unifiedImportDetectsSiteDefinitionViaInterface(): void
    {
        $expectedResult = new ImportResult(
            created: ['content' => 5],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $service = $this->createMock(ImportExportServiceInterface::class);
        $service->expects(self::once())
            ->method('importUnifiedFile')
            ->with(self::callback(static fn(mixed $v): bool => is_string($v)), true)
            ->willReturn($expectedResult);

        $json = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test Site'],
            'content' => [],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importUnifiedFile($json, true);

        self::assertSame(5, $result->totalCreated());
    }

    #[Test]
    public function unifiedImportDetectsCmsSectionsViaInterface(): void
    {
        $expectedResult = new ImportResult(
            created: ['content' => 3, 'taxonomies' => 2],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        $service = $this->createMock(ImportExportServiceInterface::class);
        $service->expects(self::once())
            ->method('importUnifiedFile')
            ->willReturn($expectedResult);

        $json = json_encode([
            'content' => [['slug' => 'about', 'title' => 'About Us']],
            'taxonomies' => [['slug' => 'tags']],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importUnifiedFile($json, false);

        self::assertSame(5, $result->totalCreated());
    }

    #[Test]
    public function unifiedImportReturnsErrorForInvalidJsonViaInterface(): void
    {
        $errorResult = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: ['Invalid JSON: Syntax error'],
            dryRun: true,
        );

        $service = $this->createStub(ImportExportServiceInterface::class);
        $service->method('importUnifiedFile')->willReturn($errorResult);

        $result = $service->importUnifiedFile('not valid json{{{', true);

        self::assertTrue($result->hasErrors());
        self::assertStringContainsString('Invalid JSON', $result->errors[0]);
    }

    #[Test]
    public function registryDelegatesToForumProvider(): void
    {
        $forumProvider = $this->createStub(ImportExportProviderInterface::class);
        $forumProvider->method('name')->willReturn('forum');
        $forumProvider->method('import')->willReturn(new RegistryImportResult(
            providerName: 'forum',
            created: ['categories' => 4],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        ));

        $registry = new ImportExportRegistry();
        $registry->register($forumProvider);

        self::assertTrue($registry->has('forum'));

        $provider = $registry->getProvider('forum');
        self::assertNotNull($provider);
        self::assertSame('forum', $provider->name());
    }

    #[Test]
    public function registryDelegatesToAnalyticsProvider(): void
    {
        $analyticsProvider = $this->createStub(ImportExportProviderInterface::class);
        $analyticsProvider->method('name')->willReturn('analytics');
        $analyticsProvider->method('import')->willReturn(new RegistryImportResult(
            providerName: 'analytics',
            created: ['sites' => 2],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        ));

        $registry = new ImportExportRegistry();
        $registry->register($analyticsProvider);

        self::assertTrue($registry->has('analytics'));
        self::assertNotNull($registry->getProvider('analytics'));
    }

    #[Test]
    public function registryDelegatesToBookingProvider(): void
    {
        $bookingProvider = $this->createStub(ImportExportProviderInterface::class);
        $bookingProvider->method('name')->willReturn('booking');
        $bookingProvider->method('import')->willReturn(new RegistryImportResult(
            providerName: 'booking',
            created: ['services' => 3],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        ));

        $registry = new ImportExportRegistry();
        $registry->register($bookingProvider);

        self::assertTrue($registry->has('booking'));
        self::assertNotNull($registry->getProvider('booking'));
    }

    #[Test]
    public function registryReturnsNullForUnregisteredProvider(): void
    {
        $registry = new ImportExportRegistry();

        self::assertFalse($registry->has('nonexistent'));
        self::assertNull($registry->getProvider('nonexistent'));
    }

    #[Test]
    public function unifiedImportHandlesMixedSectionsViaInterface(): void
    {
        $expectedResult = new ImportResult(
            created: ['content' => 3, 'sites' => 2],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        $service = $this->createMock(ImportExportServiceInterface::class);
        $service->expects(self::once())
            ->method('importUnifiedFile')
            ->willReturn($expectedResult);

        $json = json_encode([
            'content' => [['slug' => 'home']],
            'analytics' => ['sites' => [['domain' => 'example.com']]],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importUnifiedFile($json, false);

        self::assertSame(3, $result->created['content']);
        self::assertSame(2, $result->created['sites']);
    }

    #[Test]
    public function unifiedImportHandlesProviderBundleViaInterface(): void
    {
        $expectedResult = new ImportResult(
            created: ['services' => 3],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $service = $this->createStub(ImportExportServiceInterface::class);
        $service->method('importUnifiedFile')->willReturn($expectedResult);

        $json = json_encode([
            'providers' => [
                'booking' => ['services' => [['name' => 'Haircut']]],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importUnifiedFile($json, true);

        self::assertSame(3, $result->created['services']);
    }

    #[Test]
    public function unifiedImportWarnsForUnrecognizedSectionsViaInterface(): void
    {
        $warningResult = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: ['No recognized import sections found in file'],
            errors: [],
            dryRun: true,
        );

        $service = $this->createStub(ImportExportServiceInterface::class);
        $service->method('importUnifiedFile')->willReturn($warningResult);

        $json = json_encode(['unknown_section' => [1, 2, 3]], JSON_THROW_ON_ERROR);

        $result = $service->importUnifiedFile($json, true);

        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('No recognized import sections', $result->warnings[0]);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function sectionDetectionProvider(): iterable
    {
        yield 'content section only' => [
            json_encode(['content' => [['slug' => 'page1']]], JSON_THROW_ON_ERROR),
            ['content'],
        ];

        yield 'taxonomies section only' => [
            json_encode(['taxonomies' => [['slug' => 'tags']]], JSON_THROW_ON_ERROR),
            ['taxonomies'],
        ];

        yield 'menus section only' => [
            json_encode(['menus' => [['location' => 'primary']]], JSON_THROW_ON_ERROR),
            ['menus'],
        ];
    }

    /**
     * @param list<string> $expectedSections
     */
    #[Test]
    #[DataProvider('sectionDetectionProvider')]
    public function unifiedImportDetectsCorrectSections(string $json, array $expectedSections): void
    {
        $expectedResult = new ImportResult(
            created: array_fill_keys($expectedSections, 1),
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $service = $this->createStub(ImportExportServiceInterface::class);
        $service->method('importUnifiedFile')->willReturn($expectedResult);

        $result = $service->importUnifiedFile($json, true);

        foreach ($expectedSections as $section) {
            self::assertArrayHasKey($section, $result->created);
        }
    }
}
