<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\ImportConfig;

/**
 * This test covers ImportConfig in the Tools namespace.
 */
#[CoversClass(ImportConfig::class)]
final class ImportConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDryRunFalse(): void
    {
        $config = ImportConfig::fromArray([
            'dry_run_default' => false,
        ]);

        self::assertFalse($config->dryRunDefault);
    }

    #[Test]
    public function fromArrayWithMediaDownloadDisabled(): void
    {
        $config = ImportConfig::fromArray([
            'allow_external_media_download' => false,
        ]);

        self::assertFalse($config->allowExternalMediaDownload);
    }
}
