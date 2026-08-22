<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Console\Output\OutputStyle;

#[CoversClass(OutputStyle::class)]
final class OutputStyleTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    // --- Decorated mode (ANSI) ---

    #[Test]
    public function infoWritesBlueStyledMessage(): void
    {
        $style = new OutputStyle($this->output, true);

        $style->info('Hello world');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('INFO', $buffer);
        self::assertStringContainsString('Hello world', $buffer);
        self::assertStringContainsString("\033[34m", $buffer); // Blue
        self::assertStringContainsString("\033[0m", $buffer);  // Reset
    }

    #[Test]
    public function successWritesGreenStyledMessage(): void
    {
        $style = new OutputStyle($this->output, true);

        $style->success('Operation complete');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('OK', $buffer);
        self::assertStringContainsString('Operation complete', $buffer);
        self::assertStringContainsString("\033[32m", $buffer); // Green
    }

    #[Test]
    public function warningWritesYellowStyledMessage(): void
    {
        $style = new OutputStyle($this->output, true);

        $style->warning('Deprecated feature');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('WARN', $buffer);
        self::assertStringContainsString('Deprecated feature', $buffer);
        self::assertStringContainsString("\033[33m", $buffer); // Yellow
    }

    #[Test]
    public function errorWritesRedStyledMessage(): void
    {
        $style = new OutputStyle($this->output, true);

        $style->error('Something failed');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('ERROR', $buffer);
        self::assertStringContainsString('Something failed', $buffer);
        self::assertStringContainsString("\033[31m", $buffer); // Red
    }

    #[Test]
    public function commentWritesDimText(): void
    {
        $style = new OutputStyle($this->output, true);

        $style->comment('This is a comment');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('This is a comment', $buffer);
        self::assertStringContainsString("\033[2m", $buffer); // Dim
    }

    #[Test]
    public function titleWritesBoldUnderlined(): void
    {
        $style = new OutputStyle($this->output, true);

        $style->title('My Title');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('My Title', $buffer);
        self::assertStringContainsString("\033[1m", $buffer); // Bold
        self::assertStringContainsString("\033[4m", $buffer); // Underline
    }

    #[Test]
    public function sectionWritesBoldHeading(): void
    {
        $style = new OutputStyle($this->output, true);

        $style->section('Section Heading');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('Section Heading', $buffer);
        self::assertStringContainsString("\033[1m", $buffer);
    }

    // --- Plain mode (no ANSI) ---

    #[Test]
    public function infoWritesPlainLabelInNonDecoratedMode(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->info('Plain info');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('[INFO]', $buffer);
        self::assertStringContainsString('Plain info', $buffer);
        self::assertStringNotContainsString("\033[", $buffer);
    }

    #[Test]
    public function successWritesPlainLabelInNonDecoratedMode(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->success('Done');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('[OK]', $buffer);
        self::assertStringNotContainsString("\033[", $buffer);
    }

    #[Test]
    public function warningWritesPlainLabelInNonDecoratedMode(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->warning('Caution');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('[WARN]', $buffer);
    }

    #[Test]
    public function errorWritesPlainLabelInNonDecoratedMode(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->error('Failure');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('[ERROR]', $buffer);
    }

    #[Test]
    public function commentWritesPrefixInNonDecoratedMode(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->comment('A comment');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('// A comment', $buffer);
    }

    #[Test]
    public function titleWritesUnderlineInNonDecoratedMode(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->title('Plain Title');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('Plain Title', $buffer);
        self::assertStringContainsString('===========', $buffer);
    }

    #[Test]
    public function sectionWritesDashUnderlineInNonDecoratedMode(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->section('Section');

        $buffer = $this->output->buffer;
        self::assertStringContainsString('Section', $buffer);
        self::assertStringContainsString('-------', $buffer);
    }

    // --- listing ---

    #[Test]
    public function listingWritesBulletedItems(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->listing(['Item 1', 'Item 2', 'Item 3']);

        $buffer = $this->output->buffer;
        self::assertStringContainsString(' * Item 1', $buffer);
        self::assertStringContainsString(' * Item 2', $buffer);
        self::assertStringContainsString(' * Item 3', $buffer);
    }

    #[Test]
    public function listingWithDecoratedBullets(): void
    {
        $style = new OutputStyle($this->output, true);

        $style->listing(['Alpha', 'Beta']);

        $buffer = $this->output->buffer;
        self::assertStringContainsString('Alpha', $buffer);
        self::assertStringContainsString('Beta', $buffer);
    }

    // --- table ---

    #[Test]
    public function tableRendersWithHeadersAndRows(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->table(
            ['Name', 'Version'],
            [
                ['Pulsar', '1.0.0'],
                ['PHP', '8.5'],
            ],
        );

        $buffer = $this->output->buffer;
        self::assertStringContainsString('Name', $buffer);
        self::assertStringContainsString('Version', $buffer);
        self::assertStringContainsString('Pulsar', $buffer);
        self::assertStringContainsString('1.0.0', $buffer);
        self::assertStringContainsString('PHP', $buffer);
    }

    // --- progressBar ---

    #[Test]
    public function progressBarRendersInitialState(): void
    {
        $style = new OutputStyle($this->output, false);

        $advance = $style->progressBar(10);

        $buffer = $this->output->buffer;
        self::assertStringContainsString('0%', $buffer);
        self::assertStringContainsString('0/10', $buffer);
    }

    #[Test]
    public function progressBarAdvancesCorrectly(): void
    {
        $style = new OutputStyle($this->output, false);

        $advance = $style->progressBar(4);

        $advance(2);
        $buffer = $this->output->buffer;
        self::assertStringContainsString('50%', $buffer);
        self::assertStringContainsString('2/4', $buffer);

        $advance(2);
        $buffer = $this->output->buffer;
        self::assertStringContainsString('100%', $buffer);
        self::assertStringContainsString('4/4', $buffer);
    }

    #[Test]
    public function progressBarWithZeroTotal(): void
    {
        $style = new OutputStyle($this->output, false);

        $advance = $style->progressBar(0);

        $buffer = $this->output->buffer;
        self::assertStringContainsString('0%', $buffer);
    }

    // --- definitionList ---

    #[Test]
    public function definitionListWritesKeyValuePairs(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->definitionList([
            'Framework' => 'Pulsar',
            'Version' => '1.0.0',
            'PHP' => '8.5',
        ]);

        $buffer = $this->output->buffer;
        self::assertStringContainsString('Framework', $buffer);
        self::assertStringContainsString('Pulsar', $buffer);
        self::assertStringContainsString('Version', $buffer);
        self::assertStringContainsString('1.0.0', $buffer);
    }

    #[Test]
    public function definitionListAlignsKeys(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->definitionList([
            'A' => '1',
            'Long Key' => '2',
        ]);

        $buffer = $this->output->buffer;
        // Both lines should be aligned
        self::assertStringContainsString('A        :', $buffer);
        self::assertStringContainsString('Long Key :', $buffer);
    }

    // --- horizontalRule ---

    #[Test]
    public function horizontalRuleWritesLine(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->horizontalRule(20);

        $buffer = $this->output->buffer;
        self::assertStringContainsString(str_repeat("\u{2500}", 20), $buffer);
    }

    #[Test]
    public function horizontalRuleDefaultWidth(): void
    {
        $style = new OutputStyle($this->output, false);

        $style->horizontalRule();

        $buffer = $this->output->buffer;
        self::assertStringContainsString(str_repeat("\u{2500}", 60), $buffer);
    }
}
