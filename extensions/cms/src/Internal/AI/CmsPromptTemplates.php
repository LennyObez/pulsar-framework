<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\AI;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\AI\PromptTemplate;
use Pulsar\Extension\Cms\AI\PromptTemplateRegistry;

/**
 * Registers the default CMS prompt templates for the AI content assistant.
 */
#[Internal]
final class CmsPromptTemplates
{
    public static function registerDefaults(PromptTemplateRegistry $registry): void
    {
        $registry->register(new PromptTemplate(
            name: 'generate_draft',
            template: 'Write a {targetWords}-word article about the following topic. '
                . 'Use a {tone} tone throughout. '
                . 'Include an introduction, body paragraphs, and a conclusion. '
                . "Do not include a title — only the body text.\n\n"
                . 'Topic: {topic}',
            systemPrompt: 'You are an expert content writer. Produce well-structured, original content.',
            defaultTemperature: 0.7,
            defaultMaxTokens: 1000,
        ));

        $registry->register(new PromptTemplate(
            name: 'summarize',
            template: 'Summarize the following content in exactly {maxSentences} sentences. '
                . "Be concise and capture the key points.\n\n"
                . '{content}',
            systemPrompt: 'You are a summarization expert. Return only the summary, nothing else.',
            defaultTemperature: 0.3,
            defaultMaxTokens: 256,
        ));

        $registry->register(new PromptTemplate(
            name: 'suggest_title',
            template: 'Suggest exactly {count} compelling titles for the following content. '
                . "Return exactly {count} titles, one per line, without numbering or bullet points.\n\n"
                . '{content}',
            systemPrompt: 'You are a headline specialist. Return only the titles, one per line.',
            defaultTemperature: 0.8,
            defaultMaxTokens: 256,
        ));

        $registry->register(new PromptTemplate(
            name: 'suggest_meta_description',
            template: 'Write a single meta description for the following content. '
                . 'The description must be at most {maxLength} characters. '
                . 'It should be compelling and include relevant keywords for SEO. '
                . "Return only the meta description text, nothing else.\n\n"
                . '{content}',
            systemPrompt: 'You are an SEO specialist. Return only the meta description.',
            defaultTemperature: 0.5,
            defaultMaxTokens: 128,
        ));

        $registry->register(new PromptTemplate(
            name: 'translate_content',
            template: 'Translate the following content from {sourceLocale} to {targetLocale}. '
                . 'Preserve the original formatting and structure. '
                . "Return only the translated text, nothing else.\n\n"
                . '{content}',
            systemPrompt: 'You are a professional translator. Produce accurate, natural translations.',
            defaultTemperature: 0.3,
            defaultMaxTokens: 2048,
        ));

        $registry->register(new PromptTemplate(
            name: 'improve_readability',
            template: 'Rewrite the following content to improve its readability. '
                . 'Simplify complex sentences, use active voice, and break up long paragraphs. '
                . 'Preserve the original meaning and key information. '
                . "Return only the improved text, nothing else.\n\n"
                . '{content}',
            systemPrompt: 'You are a readability expert. Produce clear, easy-to-read content.',
            defaultTemperature: 0.5,
            defaultMaxTokens: 2048,
        ));

        $registry->register(new PromptTemplate(
            name: 'generate_outline',
            template: 'Create a detailed content outline for an article about the following topic. '
                . 'Structure it with H2 and H3 headings. '
                . 'Target audience: {targetAudience}. '
                . "Incorporate these keywords naturally: {keywords}.\n\n"
                . 'Topic: {topic}',
            systemPrompt: 'You are a content strategist. Produce well-structured outlines with clear heading hierarchy.',
            defaultTemperature: 0.7,
            defaultMaxTokens: 1024,
        ));

        $registry->register(new PromptTemplate(
            name: 'expand_content',
            template: 'Expand the following content to approximately {targetWords} words. '
                . 'Add more detail, examples, and explanations while preserving the original meaning and structure. '
                . "Return only the expanded text.\n\n"
                . '{content}',
            systemPrompt: 'You are a content writer. Expand content naturally without adding filler.',
            defaultTemperature: 0.6,
            defaultMaxTokens: 1600,
        ));

        $registry->register(new PromptTemplate(
            name: 'condense_content',
            template: 'Condense the following content to approximately {targetWords} words. '
                . 'Preserve the key points and main message while removing unnecessary detail. '
                . "Return only the condensed text.\n\n"
                . '{content}',
            systemPrompt: 'You are an editor. Condense content while preserving meaning and readability.',
            defaultTemperature: 0.4,
            defaultMaxTokens: 400,
        ));

        $registry->register(new PromptTemplate(
            name: 'adjust_tone',
            template: 'Rewrite the following content in a {targetTone} tone. '
                . 'Preserve the original meaning and key information while adapting the writing style. '
                . "Return only the rewritten text.\n\n"
                . '{content}',
            systemPrompt: 'You are a versatile writer. Adapt content tone while preserving meaning.',
            defaultTemperature: 0.6,
            defaultMaxTokens: 2048,
        ));

        $registry->register(new PromptTemplate(
            name: 'generate_faq',
            template: 'Generate exactly {count} frequently asked questions and answers based on the following content. '
                . "Format each as:\nQ: [question]\nA: [answer]\n\n"
                . '{content}',
            systemPrompt: 'You are a content analyst. Generate relevant, helpful FAQ pairs.',
            defaultTemperature: 0.6,
            defaultMaxTokens: 1024,
        ));

        $registry->register(new PromptTemplate(
            name: 'generate_product_description',
            template: 'Write a compelling product description for the following product. '
                . 'Use a {tone} tone. '
                . "Highlight the key features naturally.\n\n"
                . "Product: {productName}\n"
                . 'Features: {features}',
            systemPrompt: 'You are an e-commerce copywriter. Write persuasive product descriptions.',
            defaultTemperature: 0.7,
            defaultMaxTokens: 512,
        ));

        $registry->register(new PromptTemplate(
            name: 'extract_keywords',
            template: 'Extract exactly {count} SEO keywords from the following content. '
                . 'List primary keywords first, then secondary keywords. '
                . "Return one keyword or phrase per line, without numbering.\n\n"
                . '{content}',
            systemPrompt: 'You are an SEO specialist. Extract the most relevant keywords.',
            defaultTemperature: 0.3,
            defaultMaxTokens: 256,
        ));

        $registry->register(new PromptTemplate(
            name: 'analyze_seo_score',
            template: 'Analyze the SEO quality of the following content for the target keyword. '
                . 'Return a JSON object with these fields: '
                . 'score (0-100), keyword_density (percentage), title_optimization (good/fair/poor), '
                . 'meta_description_quality (good/fair/poor), heading_structure (good/fair/poor), '
                . "readability (good/fair/poor), suggestions (array of improvement strings).\n\n"
                . "Target keyword: {targetKeyword}\n\n"
                . '{content}',
            systemPrompt: 'You are an SEO analyst. Return only valid JSON with the requested structure.',
            defaultTemperature: 0.2,
            defaultMaxTokens: 1024,
        ));

        $registry->register(new PromptTemplate(
            name: 'suggest_slug',
            template: 'Suggest a single URL-friendly slug for the following title. '
                . 'Use lowercase letters, numbers, and hyphens only. '
                . "Return only the slug, nothing else.\n\n"
                . 'Title: {title}',
            systemPrompt: 'You are a URL optimization specialist. Return only the slug.',
            defaultTemperature: 0.3,
            defaultMaxTokens: 64,
        ));

        $registry->register(new PromptTemplate(
            name: 'generate_alt_text',
            template: 'Generate accessible alt text for an image. '
                . 'The alt text should be descriptive and concise (under 125 characters). '
                . "Return only the alt text, nothing else.\n\n"
                . "Image context: {imageContext}\n"
                . 'Surrounding content: {surroundingContent}',
            systemPrompt: 'You are an accessibility expert. Write descriptive, concise alt text.',
            defaultTemperature: 0.4,
            defaultMaxTokens: 128,
        ));

        $registry->register(new PromptTemplate(
            name: 'optimize_headings',
            template: 'Analyze and suggest improvements for the heading structure of the following content. '
                . 'Check for proper H1-H6 hierarchy, keyword usage, and clarity. '
                . "Return suggested headings with explanations for changes.\n\n"
                . '{content}',
            systemPrompt: 'You are an SEO and content structure expert. Optimize heading hierarchy.',
            defaultTemperature: 0.4,
            defaultMaxTokens: 512,
        ));
    }
}
