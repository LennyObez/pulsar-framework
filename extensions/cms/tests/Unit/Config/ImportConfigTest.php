<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\DuplicateResolutionPolicy;
use Pulsar\Extension\Cms\Tools\ImportConfig;

#[CoversClass(ImportConfig::class)]
final class ImportConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreSensible(): void
    {
        $config = new ImportConfig();

        self::assertSame(50 * 1024 * 1024, $config->maxImportSizeBytes);
        self::assertTrue($config->allowExternalMediaDownload);
        self::assertTrue($config->dryRunDefault);
        self::assertSame(DuplicateResolutionPolicy::Skip, $config->duplicatePolicy);
        self::assertNull($config->allowedLocales);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = ImportConfig::fromArray([]);

        self::assertSame(50 * 1024 * 1024, $config->maxImportSizeBytes);
        self::assertTrue($config->allowExternalMediaDownload);
        self::assertTrue($config->dryRunDefault);
        self::assertSame(DuplicateResolutionPolicy::Skip, $config->duplicatePolicy);
        self::assertNull($config->allowedLocales);
    }

    #[Test]
    public function fromArrayWithAllValues(): void
    {
        $config = ImportConfig::fromArray([
            'max_import_size_bytes' => 100_000_000,
            'allow_external_media_download' => false,
            'dry_run_default' => false,
            'duplicate_policy' => 'replace',
            'allowed_locales' => ['en', 'de'],
        ]);

        self::assertSame(100_000_000, $config->maxImportSizeBytes);
        self::assertFalse($config->allowExternalMediaDownload);
        self::assertFalse($config->dryRunDefault);
        self::assertSame(DuplicateResolutionPolicy::Replace, $config->duplicatePolicy);
        self::assertSame(['en', 'de'], $config->allowedLocales);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = ImportConfig::fromArray([
            'max_import_size_bytes' => 'big',
            'allow_external_media_download' => 'yes',
            'dry_run_default' => 1,
        ]);

        self::assertSame(50 * 1024 * 1024, $config->maxImportSizeBytes);
        self::assertTrue($config->allowExternalMediaDownload);
        self::assertTrue($config->dryRunDefault);
    }

    #[Test]
    public function fromArrayFiltersNonStringLocales(): void
    {
        $config = ImportConfig::fromArray([
            'allowed_locales' => ['en', 42, true, 'fr'],
        ]);

        self::assertSame(['en', 'fr'], $config->allowedLocales);
    }

    #[Test]
    public function fromArrayFallsBackToSkipForInvalidDuplicatePolicy(): void
    {
        $config = ImportConfig::fromArray([
            'duplicate_policy' => 'invalid_policy',
        ]);

        self::assertSame(DuplicateResolutionPolicy::Skip, $config->duplicatePolicy);
    }
}
