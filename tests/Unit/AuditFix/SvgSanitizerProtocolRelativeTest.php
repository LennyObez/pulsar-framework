<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;

/**
 * Verifies that SvgSanitizer blocks protocol-relative URLs (//evil.com/...)
 * which could bypass scheme-based filtering.
 */
#[CoversClass(SvgSanitizer::class)]
final class SvgSanitizerProtocolRelativeTest extends TestCase
{
    private SvgSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new SvgSanitizer();
    }

    #[Test]
    public function protocolRelativeUrlIsRemovedFromHref(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><a href="//evil.com/payload"><text>Click</text></a></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('//evil.com', $result);
    }

    #[Test]
    public function protocolRelativeUrlIsRemovedFromXlinkHref(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<a xlink:href="//evil.com/payload"><text>Click</text></a></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('//evil.com', $result);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function protocolRelativeVariantsProvider(): array
    {
        return [
            'double slash' => ['//evil.com/path'],
            'double slash with port' => ['//evil.com:8080/path'],
            'double slash with subdomain' => ['//sub.evil.com/path'],
        ];
    }

    #[Test]
    #[DataProvider('protocolRelativeVariantsProvider')]
    public function protocolRelativeVariantsAreBlocked(string $url): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><a href="' . $url . '"><text>X</text></a></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString($url, $result);
    }

    #[Test]
    public function javascriptSchemeIsAlsoBlocked(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><text>X</text></a></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringNotContainsString('javascript:', $result);
    }

    #[Test]
    public function validSvgWithNoHrefsIsPreserved(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="100" height="100" fill="red"/></svg>';
        $result = $this->sanitizer->sanitize($svg);

        self::assertStringContainsString('rect', $result);
        self::assertStringContainsString('fill="red"', $result);
    }
}
