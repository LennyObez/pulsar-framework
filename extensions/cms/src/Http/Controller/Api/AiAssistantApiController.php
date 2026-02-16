<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\AI\AiResponse;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\AI\ContentAssistant;
use Pulsar\Extension\Cms\Internal\Http\AiRequestParser;
use Pulsar\Extension\Cms\Seo\SerpPreview;
use Pulsar\Http\Message\Response;

use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * API controller exposing AI content assistant capabilities.
 *
 * All endpoints require API key authentication (CmsApiKeyMiddleware)
 * and return a uniform response shape.
 */
#[Internal(reason: 'CMS AI API controller; implementation detail')]
final readonly class AiAssistantApiController
{
    public function __construct(
        private ContentAssistant $assistant,
        private AiRequestParser $parser,
    ) {}

    public function generateDraft(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $topic = $this->parser->requireString($body, 'topic');

        if ($topic === null) {
            return $this->parser->missingFieldResponse('topic');
        }

        $tone = $this->parser->optionalString($body, 'tone', 'professional');
        $targetWords = $this->parser->optionalInt($body, 'targetWords', 500);

        return $this->formatResponse($this->assistant->generateDraft($topic, $tone, $targetWords));
    }

    public function summarize(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        $maxSentences = $this->parser->optionalInt($body, 'maxSentences', 3);

        return $this->formatResponse($this->assistant->summarize($content, $maxSentences));
    }

    public function suggestTitles(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        $count = $this->parser->optionalInt($body, 'count', 5);

        return $this->formatResponse($this->assistant->suggestTitle($content, $count));
    }

    public function suggestMeta(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        $maxLength = $this->parser->optionalInt($body, 'maxLength', 160);

        return $this->formatResponse($this->assistant->suggestMetaDescription($content, $maxLength));
    }

    public function translate(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        $sourceLocale = $this->parser->requireString($body, 'sourceLocale');

        if ($sourceLocale === null) {
            return $this->parser->missingFieldResponse('sourceLocale');
        }

        $targetLocale = $this->parser->requireString($body, 'targetLocale');

        if ($targetLocale === null) {
            return $this->parser->missingFieldResponse('targetLocale');
        }

        return $this->formatResponse($this->assistant->translateContent($content, $sourceLocale, $targetLocale));
    }

    public function improveReadability(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        return $this->formatResponse($this->assistant->improveReadability($content));
    }

    public function generateOutline(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $topic = $this->parser->requireString($body, 'topic');

        if ($topic === null) {
            return $this->parser->missingFieldResponse('topic');
        }

        $keywords = $this->parser->optionalStringArray($body, 'keywords') ?? [];
        $targetAudience = $this->parser->optionalString($body, 'targetAudience', 'general');

        return $this->formatResponse($this->assistant->generateOutline($topic, $keywords, $targetAudience));
    }

    public function expandContent(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        $targetWords = $this->parser->optionalInt($body, 'targetWords', 800);

        return $this->formatResponse($this->assistant->expandContent($content, $targetWords));
    }

    public function condenseContent(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        $targetWords = $this->parser->optionalInt($body, 'targetWords', 200);

        return $this->formatResponse($this->assistant->condenseContent($content, $targetWords));
    }

    public function adjustTone(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        $targetTone = $this->parser->requireString($body, 'targetTone');

        if ($targetTone === null) {
            return $this->parser->missingFieldResponse('targetTone');
        }

        return $this->formatResponse($this->assistant->adjustTone($content, $targetTone));
    }

    public function generateFaq(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        $count = $this->parser->optionalInt($body, 'count', 5);

        return $this->formatResponse($this->assistant->generateFaq($content, $count));
    }

    public function generateProductDescription(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $productName = $this->parser->requireString($body, 'productName');

        if ($productName === null) {
            return $this->parser->missingFieldResponse('productName');
        }

        $features = $this->parser->optionalStringArray($body, 'features');

        if ($features === null || $features === []) {
            return $this->parser->missingFieldResponse('features');
        }

        $tone = $this->parser->optionalString($body, 'tone', 'professional');

        return $this->formatResponse($this->assistant->generateProductDescription($productName, $features, $tone));
    }

    public function extractKeywords(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        $count = $this->parser->optionalInt($body, 'count', 10);

        return $this->formatResponse($this->assistant->extractKeywords($content, $count));
    }

    public function analyzeSeoScore(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        $targetKeyword = $this->parser->requireString($body, 'targetKeyword');

        if ($targetKeyword === null) {
            return $this->parser->missingFieldResponse('targetKeyword');
        }

        $result = $this->assistant->analyzeSeoScore($content, $targetKeyword);

        $status = $result->finishReason === 'error' ? 502 : 200;

        try {
            /** @var array<string, mixed> $analysis */
            $analysis = json_decode($result->content, true, flags: JSON_THROW_ON_ERROR);

            return Response::json([
                'analysis' => $analysis,
                'usage' => [
                    'input_tokens' => $result->inputTokens,
                    'output_tokens' => $result->outputTokens,
                ],
                'finish_reason' => $result->finishReason,
            ], $status);
        } catch (JsonException) {
            return $this->formatResponse($result);
        }
    }

    public function suggestSlug(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $title = $this->parser->requireString($body, 'title');

        if ($title === null) {
            return $this->parser->missingFieldResponse('title');
        }

        return $this->formatResponse($this->assistant->suggestSlug($title));
    }

    public function generateAltText(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $imageContext = $this->parser->requireString($body, 'imageContext');

        if ($imageContext === null) {
            return $this->parser->missingFieldResponse('imageContext');
        }

        $surroundingContent = $this->parser->requireString($body, 'surroundingContent');

        if ($surroundingContent === null) {
            return $this->parser->missingFieldResponse('surroundingContent');
        }

        return $this->formatResponse($this->assistant->generateAltText($imageContext, $surroundingContent));
    }

    public function optimizeHeadings(ServerRequestInterface $request): Response
    {
        $body = $this->parser->parse($request);

        if ($body instanceof Response) {
            return $body;
        }

        $content = $this->parser->requireString($body, 'content');

        if ($content === null) {
            return $this->parser->missingFieldResponse('content');
        }

        return $this->formatResponse($this->assistant->optimizeHeadings($content));
    }

    public function generateSerpPreview(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $title = is_string($body['title'] ?? null) ? $body['title'] : null;
        $metaDescription = is_string($body['metaDescription'] ?? null) ? $body['metaDescription'] : null;
        $url = is_string($body['url'] ?? null) ? $body['url'] : null;

        if ($title === null || $title === '') {
            return Response::json(['error' => 'Missing required field: title'], 422);
        }

        if ($metaDescription === null || $metaDescription === '') {
            return Response::json(['error' => 'Missing required field: metaDescription'], 422);
        }

        if ($url === null || $url === '') {
            return Response::json(['error' => 'Missing required field: url'], 422);
        }

        $preview = SerpPreview::create($title, $metaDescription, $url);

        return Response::json($preview->toArray());
    }

    private function formatResponse(AiResponse $result): Response
    {
        $status = $result->finishReason === 'error' ? 502 : 200;

        return Response::json([
            'content' => $result->content,
            'usage' => [
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
            ],
            'finish_reason' => $result->finishReason,
        ], $status);
    }
}
