<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Page;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function str_contains;

/**
 * Content negotiation for public forum page controllers.
 *
 * Returns HTML for browser requests, JSON when Accept: application/json.
 * Falls back to JSON when no template engine is available.
 */
trait RendersForumView
{
    abstract private function getTemplateEngine(): ?TemplateEngineInterface;

    /**
     * @param array<string, mixed> $data
     */
    private function respondWithView(
        ServerRequestInterface $request,
        string $template,
        array $data,
        int $statusCode = 200,
    ): Response {
        $accept = $request->getHeaderLine('Accept');

        if ($accept === 'application/json' || str_contains($accept, 'application/json')) {
            return Response::json($data, $statusCode);
        }

        $engine = $this->getTemplateEngine();

        if ($engine === null) {
            return Response::json($data, $statusCode);
        }

        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        $data['__identity'] = $identity;
        $data['__is_authenticated'] = $identity !== null && $identity->isAuthenticated();
        $data['__csrf_token'] = $request->getAttribute('csrf_token', '');

        $html = $engine->render($template, $data);

        return Response::html($html, $statusCode);
    }

    private function getIdentity(ServerRequestInterface $request): ?IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity !== null && $identity->isAuthenticated()) {
            return $identity;
        }

        return null;
    }
}
