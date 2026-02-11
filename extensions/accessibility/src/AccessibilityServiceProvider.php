<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Accessibility\Audit\AccessibilityAuditor;
use Pulsar\Extension\Accessibility\Audit\ManualChecklistGenerator;
use Pulsar\Extension\Accessibility\Contrast\ColorParser;
use Pulsar\Extension\Accessibility\Contrast\DesignTokenContrastChecker;
use Pulsar\Extension\Accessibility\Contrast\LuminanceCalculator;
use Pulsar\Extension\Accessibility\Helper\FocusManager;
use Pulsar\Extension\Accessibility\Helper\LandmarkRegion;
use Pulsar\Extension\Accessibility\Helper\LiveRegion;
use Pulsar\Extension\Accessibility\Helper\SkipNavigation;
use Pulsar\Extension\Accessibility\Validator\AltTextValidator;
use Pulsar\Extension\Accessibility\Validator\FormLabelValidator;
use Pulsar\Extension\Accessibility\Validator\HeadingHierarchyValidator;
use Pulsar\Extension\Accessibility\Validator\LandmarkStructureValidator;

/**
 * Registers accessibility services in the container.
 */
#[Internal]
final readonly class AccessibilityServiceProvider implements ServiceProviderInterface
{
    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Validators
        $container->bind(HeadingHierarchyValidator::class, static fn(): HeadingHierarchyValidator => new HeadingHierarchyValidator());
        $container->bind(FormLabelValidator::class, static fn(): FormLabelValidator => new FormLabelValidator());
        $container->bind(AltTextValidator::class, static fn(): AltTextValidator => new AltTextValidator());
        $container->bind(LandmarkStructureValidator::class, static fn(): LandmarkStructureValidator => new LandmarkStructureValidator());

        // Contrast checking
        $container->bind(ColorParser::class, static fn(): ColorParser => new ColorParser());
        $container->bind(LuminanceCalculator::class, static fn(): LuminanceCalculator => new LuminanceCalculator());
        $container->bind(DesignTokenContrastChecker::class, static function () use ($container): DesignTokenContrastChecker {
            /** @var ColorParser $colorParser */
            $colorParser = $container->get(ColorParser::class);

            /** @var LuminanceCalculator $luminance */
            $luminance = $container->get(LuminanceCalculator::class);

            return new DesignTokenContrastChecker($colorParser, $luminance);
        });

        // Helpers
        $container->bind(SkipNavigation::class, static fn(): SkipNavigation => new SkipNavigation());
        $container->bind(LandmarkRegion::class, static fn(): LandmarkRegion => new LandmarkRegion());
        $container->bind(LiveRegion::class, static fn(): LiveRegion => new LiveRegion());
        $container->bind(FocusManager::class, static fn(): FocusManager => new FocusManager());

        // Audit
        $container->bind(ManualChecklistGenerator::class, static fn(): ManualChecklistGenerator => new ManualChecklistGenerator());
        $container->bind(AccessibilityAuditor::class, static function () use ($container): AccessibilityAuditor {
            /** @var HeadingHierarchyValidator $headings */
            $headings = $container->get(HeadingHierarchyValidator::class);

            /** @var FormLabelValidator $formLabels */
            $formLabels = $container->get(FormLabelValidator::class);

            /** @var AltTextValidator $altText */
            $altText = $container->get(AltTextValidator::class);

            /** @var LandmarkStructureValidator $landmarks */
            $landmarks = $container->get(LandmarkStructureValidator::class);

            return new AccessibilityAuditor([$headings, $formLabels, $altText, $landmarks]);
        });
    }

    #[Override]
    public function provides(): array
    {
        return [
            HeadingHierarchyValidator::class,
            FormLabelValidator::class,
            AltTextValidator::class,
            LandmarkStructureValidator::class,
            ColorParser::class,
            LuminanceCalculator::class,
            DesignTokenContrastChecker::class,
            SkipNavigation::class,
            LandmarkRegion::class,
            LiveRegion::class,
            FocusManager::class,
            ManualChecklistGenerator::class,
            AccessibilityAuditor::class,
        ];
    }
}
