<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\I18n\Format\FallbackMessageFormatter;

#[CoversClass(FallbackMessageFormatter::class)]
final class FallbackMessageFormatterTest extends TestCase
{
    #[Test]
    public function replacesNamedPlaceholders(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format('Hello, {name}!', ['name' => 'World'], 'en');

        self::assertSame('Hello, World!', $result);
    }

    #[Test]
    public function leavesUnknownPlaceholdersIntact(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format('Hello, {name}! Your {role} is ready.', ['name' => 'Alice'], 'en');

        self::assertSame('Hello, Alice! Your {role} is ready.', $result);
    }

    #[Test]
    public function returnsPatternWhenNoParameters(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format('Hello!', [], 'en');

        self::assertSame('Hello!', $result);
    }

    #[Test]
    public function replacesMultiplePlaceholders(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format(
            '{greeting}, {name}! Welcome to {app}.',
            ['greeting' => 'Hi', 'name' => 'Bob', 'app' => 'Pulsar'],
            'en',
        );

        self::assertSame('Hi, Bob! Welcome to Pulsar.', $result);
    }

    #[Test]
    public function logsWarningOnFirstUse(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $formatter = new FallbackMessageFormatter($logger);

        $formatter->format('{a}', ['a' => '1'], 'en');
        $formatter->format('{b}', ['b' => '2'], 'en');
    }

    #[Test]
    public function handlesNumericValues(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format('Count: {count}', ['count' => 42], 'en');

        self::assertSame('Count: 42', $result);
    }

    #[Test]
    public function handlesFloatValues(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format('Price: {price}', ['price' => 19.99], 'en');

        self::assertSame('Price: 19.99', $result);
    }

    #[Test]
    public function handlesBooleanValues(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format('Active: {active}', ['active' => true], 'en');

        self::assertSame('Active: 1', $result);
    }

    #[Test]
    public function handlesStringableObjects(): void
    {
        $formatter = new FallbackMessageFormatter();

        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        $result = $formatter->format('Value: {obj}', ['obj' => $stringable], 'en');

        self::assertSame('Value: stringable-value', $result);
    }

    #[Test]
    public function leavesPlaceholderForNonScalarNonStringable(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format('Data: {obj}', ['obj' => ['array']], 'en');

        self::assertSame('Data: {obj}', $result);
    }

    #[Test]
    public function doesNotLogWarningWithoutLogger(): void
    {
        $formatter = new FallbackMessageFormatter(null);

        // Should not throw or error
        $result = $formatter->format('{a}', ['a' => 'val'], 'en');

        self::assertSame('val', $result);
    }

    #[Test]
    public function logsWarningOnlyOnce(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $formatter = new FallbackMessageFormatter($logger);

        // Three calls but only one warning
        $formatter->format('{a}', ['a' => '1'], 'en');
        $formatter->format('{b}', ['b' => '2'], 'en');
        $formatter->format('{c}', ['c' => '3'], 'en');
    }

    #[Test]
    public function preservesPatternWithNoPlaceholders(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format('No placeholders here.', ['unused' => 'value'], 'en');

        self::assertSame('No placeholders here.', $result);
    }

    #[Test]
    public function handlesSamePlaceholderMultipleTimes(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format('{name} said hi to {name}', ['name' => 'Alice'], 'en');

        self::assertSame('Alice said hi to Alice', $result);
    }

    #[Test]
    public function ignoresLocaleParameter(): void
    {
        $formatter = new FallbackMessageFormatter();

        $resultEn = $formatter->format('{name}', ['name' => 'test'], 'en');
        $resultFr = $formatter->format('{name}', ['name' => 'test'], 'fr');

        self::assertSame($resultEn, $resultFr);
    }

    #[Test]
    public function handlesEmptyStringValue(): void
    {
        $formatter = new FallbackMessageFormatter();

        $result = $formatter->format('Hello {name}!', ['name' => ''], 'en');

        self::assertSame('Hello !', $result);
    }
}
