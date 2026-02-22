<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\SimpleDirective;

#[CoversClass(SimpleDirective::class)]
final class SimpleDirectiveTest extends TestCase
{
    #[Test]
    public function nameReturnsConfiguredDirectiveName(): void
    {
        $directive = new SimpleDirective('endif', '<?php endif; ?>');

        self::assertSame('endif', $directive->name());
    }

    #[Test]
    public function compileReturnsStaticOutput(): void
    {
        $directive = new SimpleDirective('endif', '<?php endif; ?>');

        self::assertSame('<?php endif; ?>', $directive->compile(''));
    }

    #[Test]
    public function compileIgnoresExpression(): void
    {
        $directive = new SimpleDirective('else', '<?php else: ?>');

        self::assertSame('<?php else: ?>', $directive->compile('some expression'));
    }

    #[Test]
    #[DataProvider('closingDirectiveProvider')]
    public function closingDirectiveCompilation(string $name, string $output): void
    {
        $directive = new SimpleDirective($name, $output);

        self::assertSame($name, $directive->name());
        self::assertSame($output, $directive->compile(''));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function closingDirectiveProvider(): iterable
    {
        yield 'endif' => ['endif', '<?php endif; ?>'];
        yield 'endforeach' => ['endforeach', '<?php endforeach; ?>'];
        yield 'endfor' => ['endfor', '<?php endfor; ?>'];
        yield 'endwhile' => ['endwhile', '<?php endwhile; ?>'];
        yield 'else' => ['else', '<?php else: ?>'];
    }
}
