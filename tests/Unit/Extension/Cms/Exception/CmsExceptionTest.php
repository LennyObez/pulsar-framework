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
    public function test_content_not_found(): void
    {
        $e = CmsException::contentNotFound('abc-123');
        self::assertInstanceOf(CmsException::class, $e);
        self::assertStringContainsString('abc-123', $e->getMessage());
    }

    #[Test]
    public function test_translation_not_found(): void
    {
        $e = CmsException::translationNotFound('abc-123', 'fr');
        self::assertStringContainsString('abc-123', $e->getMessage());
        self::assertStringContainsString('fr', $e->getMessage());
    }

    #[Test]
    public function test_invalid_transition(): void
    {
        $e = CmsException::invalidTransition('draft', 'archived');
        self::assertStringContainsString('draft', $e->getMessage());
        self::assertStringContainsString('archived', $e->getMessage());
    }

    #[Test]
    public function test_circular_parent_reference(): void
    {
        $e = CmsException::circularParentReference();
        self::assertStringContainsString('Circular parent reference', $e->getMessage());
    }

    #[Test]
    public function test_max_depth_exceeded(): void
    {
        $e = CmsException::maxDepthExceeded(10);
        self::assertStringContainsString('10', $e->getMessage());
    }

    #[Test]
    public function test_content_locked(): void
    {
        $e = CmsException::contentLocked('content-id', 'user-id');
        self::assertStringContainsString('content-id', $e->getMessage());
        self::assertStringContainsString('user-id', $e->getMessage());
    }

    #[Test]
    public function test_slug_conflict(): void
    {
        $e = CmsException::slugConflict('hello-world', 'en');
        self::assertStringContainsString('hello-world', $e->getMessage());
        self::assertStringContainsString('en', $e->getMessage());
    }

    #[Test]
    public function test_invalid_slug(): void
    {
        $e = CmsException::invalidSlug('BAD SLUG!');
        self::assertStringContainsString('BAD SLUG!', $e->getMessage());
    }

    #[Test]
    public function test_sanitizer_bypass_detected(): void
    {
        $e = CmsException::sanitizerBypassDetected();
        self::assertStringContainsString('bypass', $e->getMessage());
    }

    #[Test]
    public function test_extends_runtime_exception(): void
    {
        $e = CmsException::contentNotFound('x');
        self::assertInstanceOf(RuntimeException::class, $e);
    }
}
