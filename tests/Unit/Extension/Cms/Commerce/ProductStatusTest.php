<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\ProductStatus;

#[CoversNothing]
final class ProductStatusTest extends TestCase
{
    // ── canTransitionTo ──────────────────────────────────────────────

    #[Test]
    public function draftCanTransitionToActive(): void
    {
        self::assertTrue(ProductStatus::Draft->canTransitionTo(ProductStatus::Active));
    }

    #[Test]
    public function draftCannotTransitionToArchived(): void
    {
        self::assertFalse(ProductStatus::Draft->canTransitionTo(ProductStatus::Archived));
    }

    #[Test]
    public function activeCanTransitionToArchived(): void
    {
        self::assertTrue(ProductStatus::Active->canTransitionTo(ProductStatus::Archived));
    }

    #[Test]
    public function activeCannotTransitionToDraft(): void
    {
        self::assertFalse(ProductStatus::Active->canTransitionTo(ProductStatus::Draft));
    }

    #[Test]
    public function archivedCanTransitionToDraft(): void
    {
        self::assertTrue(ProductStatus::Archived->canTransitionTo(ProductStatus::Draft));
    }

    #[Test]
    public function archivedCannotTransitionToActive(): void
    {
        self::assertFalse(ProductStatus::Archived->canTransitionTo(ProductStatus::Active));
    }

    #[Test]
    public function sameStatusCannotTransition(): void
    {
        self::assertFalse(ProductStatus::Draft->canTransitionTo(ProductStatus::Draft));
        self::assertFalse(ProductStatus::Active->canTransitionTo(ProductStatus::Active));
        self::assertFalse(ProductStatus::Archived->canTransitionTo(ProductStatus::Archived));
    }

    // ── isPubliclyVisible ────────────────────────────────────────────

    #[Test]
    public function onlyActiveIsPubliclyVisible(): void
    {
        self::assertTrue(ProductStatus::Active->isPubliclyVisible());
        self::assertFalse(ProductStatus::Draft->isPubliclyVisible());
        self::assertFalse(ProductStatus::Archived->isPubliclyVisible());
    }

    // ── label ────────────────────────────────────────────────────────

    #[Test]
    public function labelsAreCorrect(): void
    {
        self::assertSame('Draft', ProductStatus::Draft->label());
        self::assertSame('Active', ProductStatus::Active->label());
        self::assertSame('Archived', ProductStatus::Archived->label());
    }
}
