<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Page;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;

/**
 * Tag listing page: browse all tags and threads by tag.
 */
#[Internal(reason: 'Forum page controller; implementation detail')]
final readonly class TagPageController
{
    use RendersForumView;

    public function __construct(
        private TagRepositoryInterface $tagRepository,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    private function getTemplateEngine(): ?TemplateEngineInterface
    {
        return $this->templateEngine;
    }

    /**
     * GET /tags: List all tags.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $tags = $this->tagRepository->findAll();

        return $this->respondWithView($request, 'forum.tags', [
            'tags' => array_map(static fn(Tag $t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'name' => $t->name,
                'description' => $t->description,
                'usage_count' => $t->usageCount,
            ], $tags),
            'page_title' => 'Tags',
        ]);
    }
}
