<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\AI;

use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\Api\Api;

use function implode;
use function mb_strlen;

/**
 * High-level AI content assistant that constructs focused prompts
 * and delegates to the core AI client for generation.
 *
 * Uses the framework-level {@see AiClientInterface} for all LLM operations,
 * unifying provider management across the CMS and core.
 *
 * @psalm-api Public service resolved from the DI container by the CMS AI
 *            controllers and consumed by user-land code; not instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ContentAssistant
{
    public function __construct(
        private AiClientInterface $client,
        private ?PromptTemplateRegistry $templates = null,
    ) {}

    /**
     * Generate a content draft on the given topic.
     */
    public function generateDraft(string $topic, string $tone = 'professional', int $targetWords = 500): AiResponse
    {
        $template = $this->templates?->get('generate_draft');

        if ($template !== null) {
            $prompt = $template->render([
                'topic' => $topic,
                'tone' => $tone,
                'targetWords' => $targetWords,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $targetWords * 2,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = "Write a $targetWords-word article about the following topic. "
            . "Use a $tone tone throughout. "
            . 'Include an introduction, body paragraphs, and a conclusion. '
            . "Do not include a title: only the body text.\n\n"
            . "Topic: $topic";

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.7,
            maxTokens: $targetWords * 2,
            systemPrompt: 'You are an expert content writer. Produce well-structured, original content.',
        ));
    }

    /**
     * Summarize content into a concise form.
     */
    public function summarize(string $content, int $maxSentences = 3): AiResponse
    {
        $template = $this->templates?->get('summarize');

        if ($template !== null) {
            $prompt = $template->render([
                'content' => $content,
                'maxSentences' => $maxSentences,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = "Summarize the following content in exactly $maxSentences sentences. "
            . "Be concise and capture the key points.\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.3,
            maxTokens: 256,
            systemPrompt: 'You are a summarization expert. Return only the summary, nothing else.',
        ));
    }

    /**
     * Suggest titles for the given content.
     */
    public function suggestTitle(string $content, int $count = 5): AiResponse
    {
        $template = $this->templates?->get('suggest_title');

        if ($template !== null) {
            $prompt = $template->render([
                'content' => $content,
                'count' => $count,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = "Suggest exactly $count compelling titles for the following content. "
            . "Return exactly $count titles, one per line, without numbering or bullet points.\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.8,
            maxTokens: 256,
            systemPrompt: 'You are a headline specialist. Return only the titles, one per line.',
        ));
    }

    /**
     * Suggest an SEO-optimized meta description.
     */
    public function suggestMetaDescription(string $content, int $maxLength = 160): AiResponse
    {
        $template = $this->templates?->get('suggest_meta_description');

        if ($template !== null) {
            $prompt = $template->render([
                'content' => $content,
                'maxLength' => $maxLength,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = 'Write a single meta description for the following content. '
            . "The description must be at most $maxLength characters. "
            . 'It should be compelling and include relevant keywords for SEO. '
            . "Return only the meta description text, nothing else.\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.5,
            maxTokens: 128,
            systemPrompt: 'You are an SEO specialist. Return only the meta description.',
        ));
    }

    /**
     * Translate content between locales.
     */
    public function translateContent(string $content, string $sourceLocale, string $targetLocale): AiResponse
    {
        $template = $this->templates?->get('translate_content');

        if ($template !== null) {
            $prompt = $template->render([
                'content' => $content,
                'sourceLocale' => $sourceLocale,
                'targetLocale' => $targetLocale,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: mb_strlen($content) * 2,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = "Translate the following content from $sourceLocale to $targetLocale. "
            . 'Preserve the original formatting and structure. '
            . "Return only the translated text, nothing else.\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.3,
            maxTokens: mb_strlen($content) * 2,
            systemPrompt: 'You are a professional translator. Produce accurate, natural translations.',
        ));
    }

    /**
     * Suggest readability improvements for the given content.
     */
    public function improveReadability(string $content): AiResponse
    {
        $template = $this->templates?->get('improve_readability');

        if ($template !== null) {
            $prompt = $template->render(['content' => $content]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: mb_strlen($content) * 2,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = 'Rewrite the following content to improve its readability. '
            . 'Simplify complex sentences, use active voice, and break up long paragraphs. '
            . 'Preserve the original meaning and key information. '
            . "Return only the improved text, nothing else.\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.5,
            maxTokens: mb_strlen($content) * 2,
            systemPrompt: 'You are a readability expert. Produce clear, easy-to-read content.',
        ));
    }

    /**
     * Generate a structured outline with H2/H3 headings.
     *
     * @param list<string> $keywords
     */
    public function generateOutline(string $topic, array $keywords, string $targetAudience): AiResponse
    {
        $keywordList = implode(', ', $keywords);
        $template = $this->templates?->get('generate_outline');

        if ($template !== null) {
            $prompt = $template->render([
                'topic' => $topic,
                'keywords' => $keywordList,
                'targetAudience' => $targetAudience,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = 'Create a detailed content outline for an article about the following topic. '
            . 'Structure it with H2 and H3 headings. '
            . "Target audience: $targetAudience. "
            . "Incorporate these keywords naturally: $keywordList.\n\n"
            . "Topic: $topic";

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.7,
            maxTokens: 1024,
            systemPrompt: 'You are a content strategist. Produce well-structured outlines with clear heading hierarchy.',
        ));
    }

    public function expandContent(string $content, int $targetWords = 800): AiResponse
    {
        $template = $this->templates?->get('expand_content');

        if ($template !== null) {
            $prompt = $template->render([
                'content' => $content,
                'targetWords' => $targetWords,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $targetWords * 2,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = "Expand the following content to approximately $targetWords words. "
            . 'Add more detail, examples, and explanations while preserving the original meaning and structure. '
            . "Return only the expanded text.\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.6,
            maxTokens: $targetWords * 2,
            systemPrompt: 'You are a content writer. Expand content naturally without adding filler.',
        ));
    }

    public function condenseContent(string $content, int $targetWords = 200): AiResponse
    {
        $template = $this->templates?->get('condense_content');

        if ($template !== null) {
            $prompt = $template->render([
                'content' => $content,
                'targetWords' => $targetWords,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $targetWords * 2,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = "Condense the following content to approximately $targetWords words. "
            . 'Preserve the key points and main message while removing unnecessary detail. '
            . "Return only the condensed text.\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.4,
            maxTokens: $targetWords * 2,
            systemPrompt: 'You are an editor. Condense content while preserving meaning and readability.',
        ));
    }

    public function adjustTone(string $content, string $targetTone): AiResponse
    {
        $template = $this->templates?->get('adjust_tone');

        if ($template !== null) {
            $prompt = $template->render([
                'content' => $content,
                'targetTone' => $targetTone,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: mb_strlen($content) * 2,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = "Rewrite the following content in a $targetTone tone. "
            . 'Preserve the original meaning and key information while adapting the writing style. '
            . "Return only the rewritten text.\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.6,
            maxTokens: mb_strlen($content) * 2,
            systemPrompt: 'You are a versatile writer. Adapt content tone while preserving meaning.',
        ));
    }

    public function generateFaq(string $content, int $count = 5): AiResponse
    {
        $template = $this->templates?->get('generate_faq');

        if ($template !== null) {
            $prompt = $template->render([
                'content' => $content,
                'count' => $count,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = "Generate exactly $count frequently asked questions and answers based on the following content. "
            . "Format each as:\nQ: [question]\nA: [answer]\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.6,
            maxTokens: 1024,
            systemPrompt: 'You are a content analyst. Generate relevant, helpful FAQ pairs.',
        ));
    }

    /**
     * @param list<string> $features
     */
    public function generateProductDescription(string $productName, array $features, string $tone = 'professional'): AiResponse
    {
        $featureList = implode(', ', $features);
        $template = $this->templates?->get('generate_product_description');

        if ($template !== null) {
            $prompt = $template->render([
                'productName' => $productName,
                'features' => $featureList,
                'tone' => $tone,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = 'Write a compelling product description for the following product. '
            . "Use a $tone tone. "
            . "Highlight the key features naturally.\n\n"
            . "Product: $productName\n"
            . "Features: $featureList";

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.7,
            maxTokens: 512,
            systemPrompt: 'You are an e-commerce copywriter. Write persuasive product descriptions.',
        ));
    }

    public function extractKeywords(string $content, int $count = 10): AiResponse
    {
        $template = $this->templates?->get('extract_keywords');

        if ($template !== null) {
            $prompt = $template->render([
                'content' => $content,
                'count' => $count,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = "Extract exactly $count SEO keywords from the following content. "
            . 'List primary keywords first, then secondary keywords. '
            . "Return one keyword or phrase per line, without numbering.\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.3,
            maxTokens: 256,
            systemPrompt: 'You are an SEO specialist. Extract the most relevant keywords.',
        ));
    }

    public function analyzeSeoScore(string $content, string $targetKeyword): AiResponse
    {
        $template = $this->templates?->get('analyze_seo_score');

        if ($template !== null) {
            $prompt = $template->render([
                'content' => $content,
                'targetKeyword' => $targetKeyword,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = 'Analyze the SEO quality of the following content for the target keyword. '
            . 'Return a JSON object with these fields: '
            . 'score (0-100), keyword_density (percentage), title_optimization (good/fair/poor), '
            . 'meta_description_quality (good/fair/poor), heading_structure (good/fair/poor), '
            . "readability (good/fair/poor), suggestions (array of improvement strings).\n\n"
            . "Target keyword: $targetKeyword\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.2,
            maxTokens: 1024,
            systemPrompt: 'You are an SEO analyst. Return only valid JSON with the requested structure.',
        ));
    }

    public function suggestSlug(string $title): AiResponse
    {
        $template = $this->templates?->get('suggest_slug');

        if ($template !== null) {
            $prompt = $template->render(['title' => $title]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = 'Suggest a single URL-friendly slug for the following title. '
            . 'Use lowercase letters, numbers, and hyphens only. '
            . "Return only the slug, nothing else.\n\n"
            . "Title: $title";

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.3,
            maxTokens: 64,
            systemPrompt: 'You are a URL optimization specialist. Return only the slug.',
        ));
    }

    public function generateAltText(string $imageContext, string $surroundingContent): AiResponse
    {
        $template = $this->templates?->get('generate_alt_text');

        if ($template !== null) {
            $prompt = $template->render([
                'imageContext' => $imageContext,
                'surroundingContent' => $surroundingContent,
            ]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = 'Generate accessible alt text for an image. '
            . 'The alt text should be descriptive and concise (under 125 characters). '
            . "Return only the alt text, nothing else.\n\n"
            . "Image context: $imageContext\n"
            . "Surrounding content: $surroundingContent";

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.4,
            maxTokens: 128,
            systemPrompt: 'You are an accessibility expert. Write descriptive, concise alt text.',
        ));
    }

    public function optimizeHeadings(string $content): AiResponse
    {
        $template = $this->templates?->get('optimize_headings');

        if ($template !== null) {
            $prompt = $template->render(['content' => $content]);

            return $this->client->complete($prompt, new AiRequestOptions(
                temperature: $template->defaultTemperature,
                maxTokens: $template->defaultMaxTokens,
                systemPrompt: $template->systemPrompt,
            ));
        }

        $prompt = 'Analyze and suggest improvements for the heading structure of the following content. '
            . 'Check for proper H1-H6 hierarchy, keyword usage, and clarity. '
            . "Return suggested headings with explanations for changes.\n\n"
            . $content;

        return $this->client->complete($prompt, new AiRequestOptions(
            temperature: 0.4,
            maxTokens: 512,
            systemPrompt: 'You are an SEO and content structure expert. Optimize heading hierarchy.',
        ));
    }
}
