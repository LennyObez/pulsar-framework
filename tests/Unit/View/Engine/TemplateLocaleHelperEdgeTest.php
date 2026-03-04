<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\View\Engine\TemplateLocaleHelper;

/**
 * Edge cases for TemplateLocaleHelper.
 */
#[CoversClass(TemplateLocaleHelper::class)]
final class TemplateLocaleHelperEdgeTest extends TestCase
{
    #[Test]
    public function currentReturnsTranslatorLocale(): void
    {
        $translator = new FakeTranslator('fr');
        $helper = new TemplateLocaleHelper($translator);

        self::assertSame('fr', $helper->current());
    }

    #[Test]
    public function isActiveReturnsTrueWhenLocaleMatches(): void
    {
        $translator = new FakeTranslator('en');
        $helper = new TemplateLocaleHelper($translator);

        self::assertTrue($helper->isActive('en'));
        self::assertFalse($helper->isActive('fr'));
    }

    #[Test]
    public function switchUrlsReturnsEmptyWithoutUrlGenerator(): void
    {
        $translator = new FakeTranslator('en');
        $helper = new TemplateLocaleHelper($translator);

        self::assertSame([], $helper->switchUrls('/about'));
    }

    #[Test]
    public function isRtlReturnsFalseForLtrLocale(): void
    {
        $translator = new FakeTranslator('en');
        $helper = new TemplateLocaleHelper($translator);

        self::assertFalse($helper->isRtl());
    }

    #[Test]
    public function isRtlReturnsTrueForArabic(): void
    {
        $translator = new FakeTranslator('ar');
        $helper = new TemplateLocaleHelper($translator);

        self::assertTrue($helper->isRtl());
    }

    #[Test]
    public function isRtlReturnsTrueForHebrew(): void
    {
        $translator = new FakeTranslator('he');
        $helper = new TemplateLocaleHelper($translator);

        self::assertTrue($helper->isRtl());
    }
}

/**
 * @internal Concrete test double for TranslatorInterface with PHP 8.5 property hook.
 */
final class FakeTranslator implements TranslatorInterface
{
    public string $locale;

    public function __construct(string $locale)
    {
        $this->locale = $locale;
    }

    public function translate(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
    {
        return $key;
    }

    public function has(string $key, ?string $locale = null, string $domain = 'messages'): bool
    {
        return false;
    }
}
