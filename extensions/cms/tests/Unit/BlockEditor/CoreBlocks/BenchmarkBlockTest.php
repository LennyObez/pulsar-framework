<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\BenchmarkBlock;

#[CoversClass(BenchmarkBlock::class)]
final class BenchmarkBlockTest extends TestCase
{
    private BenchmarkBlock $block;

    protected function setUp(): void
    {
        $this->block = new BenchmarkBlock();
    }

    public function testType(): void
    {
        self::assertSame('benchmark', $this->block->type());
    }

    public function testRenderWithMetrics(): void
    {
        $html = $this->block->render([
            'title' => 'HTTP Performance',
            'metrics' => [
                [
                    'name' => 'Requests/sec',
                    'unit' => 'req/s',
                    'entries' => [
                        ['label' => 'Pulsar', 'value' => 15000, 'highlight' => true],
                        ['label' => 'Laravel', 'value' => 8000],
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('HTTP Performance', $html);
        self::assertStringContainsString('Requests/sec', $html);
        self::assertStringContainsString('Pulsar', $html);
        self::assertStringContainsString('Laravel', $html);
        self::assertStringContainsString('benchmark-block__row--highlight', $html);
        self::assertStringContainsString('benchmark-block__bar', $html);
    }

    public function testRenderWithSource(): void
    {
        $html = $this->block->render([
            'title' => 'Test',
            'metrics' => [],
            'source' => 'TechEmpower',
            'sourceUrl' => 'https://techempower.com',
        ]);

        self::assertStringContainsString('Source:', $html);
        self::assertStringContainsString('TechEmpower', $html);
        self::assertStringContainsString('href="https://techempower.com"', $html);
    }

    public function testRenderWithDescription(): void
    {
        $html = $this->block->render([
            'title' => 'Test',
            'description' => 'A benchmark comparison',
            'metrics' => [],
        ]);

        self::assertStringContainsString('A benchmark comparison', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'title' => '<script>xss</script>',
            'metrics' => [
                [
                    'name' => '<img src=x>',
                    'entries' => [
                        ['label' => '"><script>', 'value' => 1],
                    ],
                ],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testValidateRequiresTitleAndMetrics(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('title is required and must be a string', $errors);
        self::assertContains('metrics is required and must be an array', $errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'title' => 'Test',
            'metrics' => [
                [
                    'name' => 'Speed',
                    'entries' => [
                        ['label' => 'Pulsar', 'value' => 100],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
