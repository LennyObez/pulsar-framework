<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ComplianceBadgeBlock;

#[CoversClass(ComplianceBadgeBlock::class)]
final class ComplianceBadgeBlockTest extends TestCase
{
    private ComplianceBadgeBlock $block;

    protected function setUp(): void
    {
        $this->block = new ComplianceBadgeBlock();
    }

    public function testType(): void
    {
        self::assertSame('compliance-badge', $this->block->type());
    }

    public function testRenderWithKnownFrameworks(): void
    {
        $html = $this->block->render([
            'badges' => [
                ['framework' => 'soc2'],
                ['framework' => 'hipaa'],
                ['framework' => 'gdpr'],
            ],
        ]);

        self::assertStringContainsString('SOC 2', $html);
        self::assertStringContainsString('HIPAA', $html);
        self::assertStringContainsString('GDPR', $html);
    }

    public function testRenderWithCustomLabel(): void
    {
        $html = $this->block->render([
            'badges' => [
                ['framework' => 'soc2', 'label' => 'SOC 2 Type II'],
            ],
        ]);

        self::assertStringContainsString('SOC 2 Type II', $html);
    }

    public function testRenderWithUrl(): void
    {
        $html = $this->block->render([
            'badges' => [
                ['framework' => 'gdpr', 'url' => '/compliance/gdpr'],
            ],
        ]);

        self::assertStringContainsString('<a href="/compliance/gdpr"', $html);
    }

    public function testRenderWithoutUrlUsesDiv(): void
    {
        $html = $this->block->render([
            'badges' => [
                ['framework' => 'hipaa'],
            ],
        ]);

        self::assertStringContainsString('<div class="compliance-badge-block__badge', $html);
    }

    public function testRenderWithLogo(): void
    {
        $html = $this->block->render([
            'badges' => [
                ['framework' => 'iso-27001', 'logoUrl' => '/img/iso.png'],
            ],
        ]);

        self::assertStringContainsString('src="/img/iso.png"', $html);
    }

    public function testRenderWithStatus(): void
    {
        $html = $this->block->render([
            'badges' => [
                ['framework' => 'soc2', 'status' => 'Certified'],
            ],
        ]);

        self::assertStringContainsString('Certified', $html);
        self::assertStringContainsString('compliance-badge-block__status', $html);
    }

    public function testRenderWithLayoutAndSize(): void
    {
        $html = $this->block->render([
            'badges' => [['framework' => 'gdpr']],
            'layout' => 'grid',
            'size' => 'lg',
        ]);

        self::assertStringContainsString('compliance-badge-block--grid', $html);
        self::assertStringContainsString('compliance-badge-block--lg', $html);
    }

    public function testRenderWithTitle(): void
    {
        $html = $this->block->render([
            'badges' => [['framework' => 'gdpr']],
            'title' => 'Our Certifications',
        ]);

        self::assertStringContainsString('Our Certifications', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'badges' => [
                ['framework' => '<script>xss</script>', 'label' => '"><img src=x>'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testValidateRequiresBadges(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('badges is required and must be an array', $errors);
    }

    public function testValidateRejectsInvalidLayout(): void
    {
        $errors = $this->block->validate([
            'badges' => [['framework' => 'gdpr']],
            'layout' => 'masonry',
        ]);

        self::assertNotEmpty($errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'badges' => [
                ['framework' => 'soc2', 'label' => 'SOC 2', 'url' => '/soc2'],
            ],
            'layout' => 'grid',
            'size' => 'md',
        ]);

        self::assertSame([], $errors);
    }
}
