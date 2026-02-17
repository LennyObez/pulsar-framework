<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\FullSiteEditor;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\FullSiteEditor\FullSiteEditorService;
use Pulsar\Extension\Cms\FullSiteEditor\GlobalStylesConfig;
use Pulsar\Extension\Cms\FullSiteEditor\GlobalStylesRepositoryInterface;
use Pulsar\Extension\Cms\FullSiteEditor\TemplatePart;
use Pulsar\Extension\Cms\FullSiteEditor\TemplatePartArea;
use Pulsar\Extension\Cms\FullSiteEditor\TemplatePartRepositoryInterface;

final class FullSiteEditorServiceTest extends TestCase
{
    private FullSiteEditorService $service;
    private TemplatePartRepositoryInterface&Stub $partRepo;
    private GlobalStylesRepositoryInterface&Stub $stylesRepo;

    protected function setUp(): void
    {
        $this->partRepo = $this->createStub(TemplatePartRepositoryInterface::class);
        $this->stylesRepo = $this->createStub(GlobalStylesRepositoryInterface::class);
        $this->service = new FullSiteEditorService($this->partRepo, $this->stylesRepo);
    }

    #[Test]
    public function createTemplatePartReturnsNewPart(): void
    {
        $this->partRepo->method('findBySlug')->willReturn(null);

        $part = $this->service->createTemplatePart(
            slug: 'header',
            area: TemplatePartArea::Header,
            name: 'Main Header',
        );

        self::assertSame('header', $part->slug);
        self::assertSame(TemplatePartArea::Header, $part->area);
        self::assertSame('Main Header', $part->name);
        self::assertNotEmpty($part->id);
    }

    #[Test]
    public function createTemplatePartThrowsOnDuplicateSlug(): void
    {
        $existing = new TemplatePart('tp-001', 'header', TemplatePartArea::Header, 'Existing', '[]');
        $this->partRepo->method('findBySlug')->willReturn($existing);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');

        $this->service->createTemplatePart('header', TemplatePartArea::Header, 'Duplicate');
    }

    #[Test]
    public function createTemplatePartRejectsEmptySlug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        $this->service->createTemplatePart('', TemplatePartArea::Header, 'No Slug');
    }

    #[Test]
    public function createTemplatePartRejectsInvalidSlugCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->createTemplatePart('INVALID SLUG!', TemplatePartArea::Header, 'Bad');
    }

    #[Test]
    public function updateTemplatePartReturnsUpdatedPart(): void
    {
        $existing = new TemplatePart('tp-001', 'header', TemplatePartArea::Header, 'Old Name', '[]');
        $this->partRepo->method('findById')->willReturn($existing);

        $updated = $this->service->updateTemplatePart('tp-001', '{"blocks":[{"type":"heading"}]}', 'New Name');

        self::assertSame('tp-001', $updated->id);
        self::assertSame('New Name', $updated->name);
        self::assertStringContainsString('heading', $updated->content);
    }

    #[Test]
    public function updateTemplatePartThrowsWhenNotFound(): void
    {
        $this->partRepo->method('findById')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->service->updateTemplatePart('nonexistent', '[]');
    }

    #[Test]
    public function getGlobalStylesLoadsFromRepository(): void
    {
        $config = new GlobalStylesConfig(colors: ['primary' => '#000']);
        $this->stylesRepo->method('load')->willReturn($config);

        $result = $this->service->getGlobalStyles();

        self::assertSame('#000', $result->colors['primary']);
    }

    #[Test]
    public function compileGlobalCssReturnsCompiledOutput(): void
    {
        $config = new GlobalStylesConfig(
            colors: ['primary' => '#1e40af'],
            typography: ['body' => 'sans-serif'],
        );
        $this->stylesRepo->method('load')->willReturn($config);

        $css = $this->service->compileGlobalCss();

        self::assertStringContainsString('--color-primary: #1e40af;', $css);
        self::assertStringContainsString('--font-body: sans-serif;', $css);
    }

    #[Test]
    public function getPartsForAreaDelegates(): void
    {
        $parts = [
            new TemplatePart('tp-001', 'header', TemplatePartArea::Header, 'H1', '[]'),
        ];
        $this->partRepo->method('findByArea')->willReturn($parts);

        $result = $this->service->getPartsForArea(TemplatePartArea::Header);

        self::assertCount(1, $result);
        self::assertSame('header', $result[0]->slug);
    }

    #[Test]
    public function slugNormalizesToLowercase(): void
    {
        $this->partRepo->method('findBySlug')->willReturn(null);

        $part = $this->service->createTemplatePart('FOOTER', TemplatePartArea::Footer, 'Footer');

        self::assertSame('footer', $part->slug);
    }
}
