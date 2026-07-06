<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Documentation\DocumentationConfig;

#[CoversClass(DocumentationConfig::class)]
final class DocumentationConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabledAndEmpty(): void
    {
        $config = new DocumentationConfig();

        self::assertFalse($config->enabled);
        self::assertSame([], $config->versions);
        self::assertFalse($config->isUsable());
    }

    #[Test]
    public function fromArrayBuildsVersionsWithDefaults(): void
    {
        $config = DocumentationConfig::fromArray([
            'enabled' => true,
            'versions' => [
                ['version' => '1.1', 'label' => '1.1 (latest)', 'base_path' => 'docs/1.1', 'is_latest' => true],
                ['version' => '1.0'],
            ],
        ]);

        self::assertTrue($config->isUsable());
        self::assertCount(2, $config->versions);

        self::assertSame('1.1', $config->versions[0]->version);
        self::assertSame('1.1 (latest)', $config->versions[0]->label);
        self::assertTrue($config->versions[0]->isLatest);

        // Defaults: label falls back to version, base_path to docs/{version}.
        self::assertSame('1.0', $config->versions[1]->label);
        self::assertSame('docs/1.0', $config->versions[1]->basePath);
        self::assertFalse($config->versions[1]->isLatest);
    }

    #[Test]
    public function fromArraySkipsMalformedOrVersionlessEntries(): void
    {
        /** @psalm-suppress InvalidArgument intentionally malformed config */
        $config = DocumentationConfig::fromArray([
            'enabled' => true,
            'versions' => [
                'not-an-array',
                ['label' => 'no version key'],
                ['version' => ''],
                ['version' => '2.0'],
            ],
        ]);

        self::assertCount(1, $config->versions);
        self::assertSame('2.0', $config->versions[0]->version);
    }

    #[Test]
    public function isNotUsableWhenEnabledButNoVersions(): void
    {
        self::assertFalse(DocumentationConfig::fromArray(['enabled' => true])->isUsable());
    }
}
