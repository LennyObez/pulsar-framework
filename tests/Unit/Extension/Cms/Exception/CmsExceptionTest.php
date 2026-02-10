<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;
use RuntimeException;

#[CoversClass(CmsException::class)]
final class CmsExceptionTest extends TestCase
{
    #[Test]
    public function contentNotFound(): void
    {
        $e = CmsException::contentNotFound('abc-123');
        self::assertInstanceOf(CmsException::class, $e);
        self::assertStringContainsString('abc-123', $e->getMessage());
    }

    #[Test]
    public function translationNotFound(): void
    {
        $e = CmsException::translationNotFound('abc-123', 'fr');
        self::assertStringContainsString('abc-123', $e->getMessage());
        self::assertStringContainsString('fr', $e->getMessage());
    }

    #[Test]
    public function invalidTransition(): void
    {
        $e = CmsException::invalidTransition('draft', 'archived');
        self::assertStringContainsString('draft', $e->getMessage());
        self::assertStringContainsString('archived', $e->getMessage());
    }

    #[Test]
    public function circularParentReference(): void
    {
        $e = CmsException::circularParentReference();
        self::assertStringContainsString('Circular parent reference', $e->getMessage());
    }

    #[Test]
    public function maxDepthExceeded(): void
    {
        $e = CmsException::maxDepthExceeded(10);
        self::assertStringContainsString('10', $e->getMessage());
    }

    #[Test]
    public function contentLocked(): void
    {
        $e = CmsException::contentLocked('content-id', 'user-id');
        self::assertStringContainsString('content-id', $e->getMessage());
        self::assertStringContainsString('user-id', $e->getMessage());
    }

    #[Test]
    public function slugConflict(): void
    {
        $e = CmsException::slugConflict('hello-world', 'en');
        self::assertStringContainsString('hello-world', $e->getMessage());
        self::assertStringContainsString('en', $e->getMessage());
    }

    #[Test]
    public function invalidSlug(): void
    {
        $e = CmsException::invalidSlug('BAD SLUG!');
        self::assertStringContainsString('BAD SLUG!', $e->getMessage());
    }

    #[Test]
    public function sanitizerBypassDetected(): void
    {
        $e = CmsException::sanitizerBypassDetected();
        self::assertStringContainsString('bypass', $e->getMessage());
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $e = CmsException::contentNotFound('x');
        self::assertInstanceOf(RuntimeException::class, $e);
    }
}
