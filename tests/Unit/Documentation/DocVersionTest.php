<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Documentation\DocVersion;

#[CoversClass(DocVersion::class)]
final class DocVersionTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $version = new DocVersion(
            version: '1.2',
            label: 'Version 1.2',
            basePath: '/docs/1.2',
            isLatest: true,
            isPrerelease: false,
        );

        self::assertSame('1.2', $version->version);
        self::assertSame('Version 1.2', $version->label);
        self::assertSame('/docs/1.2', $version->basePath);
        self::assertTrue($version->isLatest);
        self::assertFalse($version->isPrerelease);
    }

    #[Test]
    public function defaultsAreNotLatestNotPrerelease(): void
    {
        $version = new DocVersion('2.0', 'v2', '/docs/2.0');

        self::assertFalse($version->isLatest);
        self::assertFalse($version->isPrerelease);
    }

    #[Test]
    public function urlPrefixIncludesVersion(): void
    {
        $version = new DocVersion('3.1', 'v3.1', '/docs/3.1');

        self::assertSame('/docs/3.1', $version->urlPrefix());
    }

    #[Test]
    public function urlPrefixForPrerelease(): void
    {
        $version = new DocVersion('4.0-rc1', 'v4 RC1', '/docs/4.0-rc1', isPrerelease: true);

        self::assertSame('/docs/4.0-rc1', $version->urlPrefix());
        self::assertTrue($version->isPrerelease);
    }
}
