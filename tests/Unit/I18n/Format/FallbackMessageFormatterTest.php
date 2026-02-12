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
}
