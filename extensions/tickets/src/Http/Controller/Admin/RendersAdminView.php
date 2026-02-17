<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateAuthHelper;

use function str_contains;

/**
 * Content negotiation for ticket admin controllers.
 *
 * Returns HTML (via template engine) for browser requests,
 * JSON when Accept: application/json is requested.
 * Falls back to JSON when no template engine is available.
 */
trait RendersAdminView
{
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

        if ($this->templateEngine === null) {
            return Response::json($data, $statusCode);
        }

        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        $data['__auth'] = new TemplateAuthHelper($this->gate, $identity);

        $html = $this->templateEngine->render($template, $data);

        return Response::html($html, $statusCode);
    }

    /**
     * @throws AuthenticationException If no authenticated identity is present
     */
    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw AuthenticationException::invalidCredentials();
        }

        return $identity;
    }

    /**
     * @throws AuthorizationException If the identity lacks the required permission
     */
    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw AuthorizationException::permissionDenied($permission);
        }
    }
}
