<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\License;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\License\LicenseBadgeRenderer;
use Pulsar\Extension\Cms\Media\License\LicenseType;

#[CoversClass(LicenseBadgeRenderer::class)]
final class LicenseBadgeRendererTest extends TestCase
{
    #[Test]
    public function render_produces_valid_html_structure(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(LicenseType::CcBy);

        self::assertStringContainsString('pui-license-badge', $html);
        self::assertStringContainsString('role="contentinfo"', $html);
        self::assertStringContainsString('aria-label="License information"', $html);
    }

    #[Test]
    public function render_includes_link_for_creative_commons(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(LicenseType::CcBy);

        self::assertStringContainsString('href="https://creativecommons.org/licenses/by/4.0/"', $html);
        self::assertStringContainsString('rel="license noopener"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('CC-BY', $html);
    }

    #[Test]
    public function render_omits_link_for_all_rights_reserved(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(LicenseType::AllRightsReserved);

        self::assertStringNotContainsString('<a ', $html);
        self::assertStringContainsString('All Rights Reserved', $html);
    }

    #[Test]
    public function render_includes_author_name(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(LicenseType::CcBy, authorName: 'Jane Doe');

        self::assertStringContainsString('pui-license-badge__author', $html);
        self::assertStringContainsString('Jane Doe', $html);
    }

    #[Test]
    public function render_prefers_copyright_holder_over_author(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(
            LicenseType::CcBy,
            authorName: 'Jane Doe',
            copyrightHolder: 'Acme Photography Inc.',
        );

        self::assertStringContainsString('Acme Photography Inc.', $html);
        self::assertStringNotContainsString('Jane Doe', $html);
    }

    #[Test]
    public function render_includes_date_when_provided(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(LicenseType::CcBy, date: '2025');

        self::assertStringContainsString('pui-license-badge__date', $html);
        self::assertStringContainsString('2025', $html);
    }

    #[Test]
    public function render_omits_author_when_null(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(LicenseType::CcBy);

        self::assertStringNotContainsString('pui-license-badge__author', $html);
    }

    #[Test]
    public function render_omits_date_when_null(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(LicenseType::CcBy);

        self::assertStringNotContainsString('pui-license-badge__date', $html);
    }

    #[Test]
    public function render_escapes_html_in_author_name(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(LicenseType::CcBy, authorName: '<script>alert("xss")</script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function render_escapes_html_in_copyright_holder(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(
            LicenseType::CcBy,
            copyrightHolder: 'O\'Malley & "Friends"',
        );

        self::assertStringContainsString('O&#039;Malley &amp; &quot;Friends&quot;', $html);
    }

    #[Test]
    public function render_omits_empty_author(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(LicenseType::CcBy, authorName: '');

        self::assertStringNotContainsString('pui-license-badge__author', $html);
    }

    #[Test]
    public function render_omits_empty_date(): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render(LicenseType::CcBy, date: '');

        self::assertStringNotContainsString('pui-license-badge__date', $html);
    }

    #[Test]
    #[DataProvider('allLicenseTypesProvider')]
    public function render_works_for_every_license_type(LicenseType $license): void
    {
        $renderer = new LicenseBadgeRenderer();

        $html = $renderer->render($license, authorName: 'Test', date: '2025');

        self::assertStringContainsString('pui-license-badge', $html);
        self::assertStringContainsString($license->value, $html);
    }

    /**
     * @return iterable<string, array{LicenseType}>
     */
    public static function allLicenseTypesProvider(): iterable
    {
        foreach (LicenseType::cases() as $case) {
            yield $case->value => [$case];
        }
    }
}
