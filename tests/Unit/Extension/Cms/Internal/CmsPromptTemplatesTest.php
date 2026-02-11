<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\AI\PromptTemplateRegistry;
use Pulsar\Extension\Cms\Internal\AI\CmsPromptTemplates;

#[CoversClass(CmsPromptTemplates::class)]
final class CmsPromptTemplatesTest extends TestCase
{
    #[Test]
    public function registerDefaultsRegistersAllTemplates(): void
    {
        $registry = new PromptTemplateRegistry();
        CmsPromptTemplates::registerDefaults($registry);

        // Should register 17 default prompt templates
        self::assertTrue($registry->has('generate_draft'));
        self::assertTrue($registry->has('summarize'));
        self::assertTrue($registry->has('suggest_title'));
        self::assertTrue($registry->has('suggest_meta_description'));
        self::assertTrue($registry->has('translate_content'));
        self::assertTrue($registry->has('improve_readability'));
        self::assertTrue($registry->has('generate_outline'));
        self::assertTrue($registry->has('expand_content'));
        self::assertTrue($registry->has('condense_content'));
        self::assertTrue($registry->has('adjust_tone'));
        self::assertTrue($registry->has('generate_faq'));
        self::assertTrue($registry->has('generate_product_description'));
        self::assertTrue($registry->has('extract_keywords'));
        self::assertTrue($registry->has('analyze_seo_score'));
        self::assertTrue($registry->has('suggest_slug'));
        self::assertTrue($registry->has('generate_alt_text'));
        self::assertTrue($registry->has('optimize_headings'));
    }

    #[Test]
    public function generateDraftTemplateHasTopicPlaceholder(): void
    {
        $registry = new PromptTemplateRegistry();
        CmsPromptTemplates::registerDefaults($registry);

        $template = $registry->get('generate_draft');
        self::assertNotNull($template);

        self::assertStringContainsString('{topic}', $template->template);
        self::assertStringContainsString('{targetWords}', $template->template);
        self::assertStringContainsString('{tone}', $template->template);
    }

    #[Test]
    public function summarizeTemplateHasContentPlaceholder(): void
    {
        $registry = new PromptTemplateRegistry();
        CmsPromptTemplates::registerDefaults($registry);

        $template = $registry->get('summarize');
        self::assertNotNull($template);

        self::assertStringContainsString('{content}', $template->template);
        self::assertStringContainsString('{maxSentences}', $template->template);
        self::assertSame(0.3, $template->defaultTemperature);
    }

    #[Test]
    public function translateContentTemplateHasLocales(): void
    {
        $registry = new PromptTemplateRegistry();
        CmsPromptTemplates::registerDefaults($registry);

        $template = $registry->get('translate_content');
        self::assertNotNull($template);

        self::assertStringContainsString('{sourceLocale}', $template->template);
        self::assertStringContainsString('{targetLocale}', $template->template);
    }

    #[Test]
    public function allTemplatesHaveSystemPrompts(): void
    {
        $registry = new PromptTemplateRegistry();
        CmsPromptTemplates::registerDefaults($registry);

        $names = [
            'generate_draft', 'summarize', 'suggest_title', 'suggest_meta_description',
            'translate_content', 'improve_readability', 'generate_outline', 'expand_content',
            'condense_content', 'adjust_tone', 'generate_faq', 'generate_product_description',
            'extract_keywords', 'analyze_seo_score', 'suggest_slug', 'generate_alt_text',
            'optimize_headings',
        ];

        foreach ($names as $name) {
            $template = $registry->get($name);
            self::assertNotNull($template, "Template '{$name}' should be registered");
            self::assertNotEmpty($template->systemPrompt, "Template '{$name}' should have a system prompt");
        }
    }
}
