<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\ViewException;

/**
 * Edge case coverage for ViewException static factories.
 */
#[CoversClass(ViewException::class)]
final class ViewExceptionEdgeTest extends TestCase
{
    #[Test]
    public function sandboxViolationIncludesAllParameters(): void
    {
        $ex = ViewException::sandboxViolation('user-template', 'exec', 'function');

        self::assertStringContainsString('user-template', $ex->getMessage());
        self::assertStringContainsString('exec', $ex->getMessage());
        self::assertStringContainsString('function', $ex->getMessage());
        self::assertStringContainsString('not allowed', $ex->getMessage());
    }

    #[Test]
    public function typedTemplateViolationIncludesAllParameters(): void
    {
        $ex = ViewException::typedTemplateViolation('page', 'count', 'int', 'string');

        self::assertStringContainsString('page', $ex->getMessage());
        self::assertStringContainsString('$count', $ex->getMessage());
        self::assertStringContainsString('int', $ex->getMessage());
        self::assertStringContainsString('string', $ex->getMessage());
    }

    #[Test]
    public function allFactoriesReturnViewException(): void
    {
        $factories = [
            ViewException::templateNotFound('t', 'p'),
            ViewException::compilationFailed('t', 'r'),
            ViewException::cacheWriteFailed('p'),
            ViewException::phpDirectiveDisabled('t'),
            ViewException::sandboxStepLimitExceeded(100),
            ViewException::sandboxLoopLimitExceeded(50),
            ViewException::sandboxOutputSizeLimitExceeded(1024),
            ViewException::sandboxWallClockExceeded(),
            ViewException::rawOutputInUntrustedMode(),
            ViewException::invalidDirective('d', 'r'),
            ViewException::sandboxViolation('t', 's', 'k'),
            ViewException::typedTemplateViolation('t', 'v', 'e', 'a'),
            ViewException::circularInheritance('c', 'p'),
            ViewException::undefinedSection('s', 't'),
            ViewException::includeNotAllowed('id'),
        ];

        foreach ($factories as $ex) {
            self::assertInstanceOf(ViewException::class, $ex);
            self::assertNotEmpty($ex->getMessage());
        }
    }
}
