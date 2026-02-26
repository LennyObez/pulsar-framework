<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\ViewException;
use RuntimeException;

#[CoversClass(ViewException::class)]
final class ViewExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = ViewException::templateNotFound('test', '/views');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function templateNotFoundIncludesNameAndPaths(): void
    {
        $exception = ViewException::templateNotFound('layouts.main', '/views, /vendor/views');

        self::assertStringContainsString('layouts.main', $exception->getMessage());
        self::assertStringContainsString('/views, /vendor/views', $exception->getMessage());
    }

    #[Test]
    public function compilationFailedIncludesTemplateAndReason(): void
    {
        $exception = ViewException::compilationFailed('home.index', 'unexpected end of file');

        self::assertStringContainsString('home.index', $exception->getMessage());
        self::assertStringContainsString('unexpected end of file', $exception->getMessage());
    }

    #[Test]
    public function cacheWriteFailedIncludesPath(): void
    {
        $exception = ViewException::cacheWriteFailed('/tmp/cache/test.php');

        self::assertStringContainsString('/tmp/cache/test.php', $exception->getMessage());
    }

    #[Test]
    public function phpDirectiveDisabledIncludesTemplate(): void
    {
        $exception = ViewException::phpDirectiveDisabled('admin.dashboard');

        self::assertStringContainsString('admin.dashboard', $exception->getMessage());
        self::assertStringContainsString('@php', $exception->getMessage());
    }

    #[Test]
    public function sandboxStepLimitExceededIncludesLimit(): void
    {
        $exception = ViewException::sandboxStepLimitExceeded(10_000);

        self::assertStringContainsString('10000', $exception->getMessage());
        self::assertStringContainsString('step limit', $exception->getMessage());
    }

    #[Test]
    public function sandboxLoopLimitExceededIncludesLimit(): void
    {
        $exception = ViewException::sandboxLoopLimitExceeded(1_000);

        self::assertStringContainsString('1000', $exception->getMessage());
        self::assertStringContainsString('loop iteration limit', $exception->getMessage());
    }

    #[Test]
    public function sandboxOutputSizeLimitExceededIncludesLimit(): void
    {
        $exception = ViewException::sandboxOutputSizeLimitExceeded(1_048_576);

        self::assertStringContainsString('1048576', $exception->getMessage());
        self::assertStringContainsString('output size limit', $exception->getMessage());
    }

    #[Test]
    public function sandboxWallClockExceededHasMessage(): void
    {
        $exception = ViewException::sandboxWallClockExceeded();

        self::assertStringContainsString('wall-clock', $exception->getMessage());
    }

    #[Test]
    public function rawOutputInUntrustedModeHasMessage(): void
    {
        $exception = ViewException::rawOutputInUntrustedMode();

        self::assertStringContainsString('Raw unescaped output', $exception->getMessage());
        self::assertStringContainsString('untrusted', $exception->getMessage());
    }

    #[Test]
    public function invalidDirectiveIncludesNameAndReason(): void
    {
        $exception = ViewException::invalidDirective('foreach', 'missing expression');

        self::assertStringContainsString('@foreach', $exception->getMessage());
        self::assertStringContainsString('missing expression', $exception->getMessage());
    }

    #[Test]
    public function circularInheritanceIncludesTemplates(): void
    {
        $exception = ViewException::circularInheritance('child', 'parent');

        self::assertStringContainsString('child', $exception->getMessage());
        self::assertStringContainsString('parent', $exception->getMessage());
        self::assertStringContainsString('Circular', $exception->getMessage());
    }

    #[Test]
    public function undefinedSectionIncludesSectionAndTemplate(): void
    {
        $exception = ViewException::undefinedSection('sidebar', 'layouts.main');

        self::assertStringContainsString('sidebar', $exception->getMessage());
        self::assertStringContainsString('layouts.main', $exception->getMessage());
    }

    #[Test]
    public function includeNotAllowedIncludesTemplateId(): void
    {
        $exception = ViewException::includeNotAllowed('user-header');

        self::assertStringContainsString('user-header', $exception->getMessage());
        self::assertStringContainsString('allowlist', $exception->getMessage());
    }
}
