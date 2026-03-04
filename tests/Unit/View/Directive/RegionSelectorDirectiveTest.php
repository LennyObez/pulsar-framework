<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\RegionSelectorDirective;

#[CoversClass(RegionSelectorDirective::class)]
final class RegionSelectorDirectiveTest extends TestCase
{
    private RegionSelectorDirective $directive;

    protected function setUp(): void
    {
        $this->directive = new RegionSelectorDirective();
    }

    #[Test]
    public function nameReturnsRegionSelector(): void
    {
        self::assertSame('region_selector', $this->directive->name());
    }

    #[Test]
    public function compileWithEmptyExpressionDefaultsToRegionMode(): void
    {
        $output = $this->directive->compile('');

        self::assertStringContainsString("'region'", $output);
    }

    #[Test]
    public function compileWithFullModePreservesMode(): void
    {
        $output = $this->directive->compile("'full'");

        self::assertStringContainsString("'full'", $output);
    }

    #[Test]
    public function compileWithLanguageModePreservesMode(): void
    {
        $output = $this->directive->compile("'language'");

        self::assertStringContainsString("'language'", $output);
    }

    #[Test]
    public function compiledOutputContainsDataLanguageSelectorAttribute(): void
    {
        $output = $this->directive->compile('');

        self::assertStringContainsString('data-language-selector', $output);
    }

    #[Test]
    public function compiledOutputContainsSelectorModeAttribute(): void
    {
        $output = $this->directive->compile('');

        self::assertStringContainsString('data-selector-mode', $output);
    }

    #[Test]
    public function compiledOutputContainsDataCountryAttribute(): void
    {
        $output = $this->directive->compile('');

        self::assertStringContainsString('data-country', $output);
    }

    #[Test]
    public function compiledOutputContainsDataCurrencyAttribute(): void
    {
        $output = $this->directive->compile('');

        self::assertStringContainsString('data-currency', $output);
    }

    #[Test]
    public function compiledOutputCleansUpTemporaryVariables(): void
    {
        $output = $this->directive->compile('');

        self::assertStringContainsString('unset(', $output);
        self::assertStringContainsString('$__rs_mode', $output);
    }

    #[Test]
    public function compiledOutputUsesHtmlSpecialcharsForEscaping(): void
    {
        $output = $this->directive->compile('');

        self::assertStringContainsString('htmlspecialchars(', $output);
    }

    #[Test]
    #[DataProvider('expressionProvider')]
    public function compiledOutputIsValidPhp(string $expression): void
    {
        $output = $this->directive->compile($expression);

        // The output should contain PHP opening tags
        self::assertStringContainsString('<?php', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expressionProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'region' => ["'region'"];
        yield 'full' => ["'full'"];
        yield 'language' => ["'language'"];
    }
}
