<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Format\IcuMessageFormatter;

#[CoversClass(IcuMessageFormatter::class)]
#[RequiresPhpExtension('intl')]
final class IcuMessageFormatterTest extends TestCase
{
    #[Test]
    public function formatsSimplePlaceholders(): void
    {
        $formatter = new IcuMessageFormatter();

        $result = $formatter->format('Hello, {name}!', ['name' => 'World'], 'en');

        self::assertSame('Hello, World!', $result);
    }

    #[Test]
    public function returnsPatternWhenNoParameters(): void
    {
        $formatter = new IcuMessageFormatter();

        $result = $formatter->format('Hello!', [], 'en');

        self::assertSame('Hello!', $result);
    }

    #[Test]
    public function formatsPlurals(): void
    {
        $formatter = new IcuMessageFormatter();

        $pattern = '{count, plural, =0{No items} one{# item} other{# items}}';

        self::assertSame('No items', $formatter->format($pattern, ['count' => 0], 'en'));
        self::assertSame('1 item', $formatter->format($pattern, ['count' => 1], 'en'));
        self::assertSame('5 items', $formatter->format($pattern, ['count' => 5], 'en'));
    }

    #[Test]
    public function returnsPatternOnInvalidIcu(): void
    {
        $formatter = new IcuMessageFormatter();

        $result = $formatter->format('{count, plural, invalid', ['count' => 1], 'en');

        self::assertSame('{count, plural, invalid', $result);
    }
}
