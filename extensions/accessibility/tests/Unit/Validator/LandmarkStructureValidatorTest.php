<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Validator;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Validator\LandmarkStructureValidator;
use Pulsar\Extension\Accessibility\Validator\Severity;

use function count;

final class LandmarkStructureValidatorTest extends TestCase
{
    private LandmarkStructureValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LandmarkStructureValidator();
    }

    #[Test]
    public function html_with_proper_landmarks_produces_no_violations(): void
    {
        $html = '<nav aria-label="Main">Links</nav><main>Content</main>';

        $violations = $this->validator->validate($html);

        self::assertSame([], $violations);
    }

    #[Test]
    public function html_without_main_landmark_detects_warning(): void
    {
        $html = '<nav aria-label="Primary">Links</nav><div>Content</div>';

        $violations = $this->validator->validate($html);

        $mainWarnings = array_filter(
            $violations,
            static fn($v) => $v->rule === 'missing-main-landmark',
        );
        self::assertCount(1, $mainWarnings);

        $warning = array_values($mainWarnings)[0];
        self::assertSame(Severity::Warning, $warning->severity);
        self::assertSame('1.3.1', $warning->wcagCriterion);
    }

    #[Test]
    public function multiple_main_elements_without_labels_detects_error(): void
    {
        $html = '<nav aria-label="Primary">Links</nav><main>First</main><main>Second</main>';

        $violations = $this->validator->validate($html);

        $mainErrors = array_filter(
            $violations,
            static fn($v) => $v->rule === 'multiple-main-landmarks',
        );
        self::assertCount(1, $mainErrors);

        $error = array_values($mainErrors)[0];
        self::assertSame(Severity::Error, $error->severity);
        self::assertSame('4.1.2', $error->wcagCriterion);
    }

    #[Test]
    public function multiple_navs_with_unique_labels_produces_no_nav_violations(): void
    {
        $html = '<nav aria-label="Primary">Links</nav><nav aria-label="Footer">More links</nav><main>Content</main>';

        $violations = $this->validator->validate($html);

        $navViolations = array_filter(
            $violations,
            static fn($v) => str_contains($v->rule, 'landmark-label') || str_contains($v->rule, 'landmark-missing-label'),
        );
        self::assertCount(0, $navViolations);
    }

    #[Test]
    public function missing_nav_landmark_detects_warning(): void
    {
        $html = '<main>Content only, no navigation</main>';

        $violations = $this->validator->validate($html);

        $navWarnings = array_filter(
            $violations,
            static fn($v) => $v->rule === 'missing-nav-landmark',
        );
        self::assertCount(1, $navWarnings);
    }

    #[Test]
    public function multiple_navs_without_labels_detects_violations(): void
    {
        $html = '<nav>Links</nav><nav>More links</nav><main>Content</main>';

        $violations = $this->validator->validate($html);

        $labelViolations = array_filter(
            $violations,
            static fn($v) => $v->rule === 'duplicate-landmark-missing-label',
        );
        self::assertGreaterThanOrEqual(1, count($labelViolations));
    }

    #[Test]
    public function empty_html_detects_missing_landmarks(): void
    {
        $html = '<p>Nothing useful here.</p>';

        $violations = $this->validator->validate($html);

        $rules = array_map(static fn($v) => $v->rule, $violations);
        self::assertContains('missing-main-landmark', $rules);
        self::assertContains('missing-nav-landmark', $rules);
    }
}
