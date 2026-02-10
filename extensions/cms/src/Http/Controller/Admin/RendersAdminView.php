<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateAuthHelper;
use RuntimeException;

use function in_array;
use function method_exists;
use function property_exists;
use function str_contains;

/**
 * Content negotiation for admin controllers.
 *
 * Returns HTML (via template engine) for browser requests,
 * JSON when Accept: application/json is requested.
 * Falls back to JSON when no template engine is available.
 *
 * Expects the using class to have:
 *  - `$this->templateEngine` (nullable TemplateEngineInterface)
 *  - `$this->gate` (GateInterface, optional — for @can directive support)
 */
trait RendersAdminView
{
    /**
     * Respond with rendered HTML or JSON based on Accept header.
     *
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

        // Inject auth context so @can/@auth/@guest directives work in templates
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');
        $gate = property_exists($this, 'gate') ? $this->gate : null;

        $data['__auth'] = new TemplateAuthHelper($gate, $identity);

        $html = $this->templateEngine->render($template, $data);

        return Response::html($html, $statusCode);
    }

    /**
     * Require an authenticated identity from the request.
     *
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
     * Require step-up authentication for sensitive operations.
     *
     * @throws AuthorizationException If step-up has not been verified
     */
    private function requireStepUp(ServerRequestInterface $request): void
    {
        $stepUp = $request->getAttribute('step_up_verified', false);

        if ($stepUp !== true) {
            throw AuthorizationException::stepUpRequired();
        }
    }

    /**
     * Authorize an identity against a specific permission.
     *
     * @throws AuthorizationException If the identity lacks the required permission
     */
    private function authorize(IdentityInterface $identity, string $permission): void
    {
        $gate = property_exists($this, 'gate') ? $this->gate : null;

        if ($gate !== null && $gate->denies($identity, $permission)) {
            throw AuthorizationException::permissionDenied($permission);
        }
    }

    /**
     * Validate that the authenticated identity has access to the requested tenant.
     *
     * In single-tenant mode (no tenant_id attribute), returns null.
     * In multi-tenant mode, verifies the identity belongs to the tenant.
     *
     * @return string|null The validated tenant ID, or null for single-tenant mode
     *
     * @throws RuntimeException If authentication is missing or the identity does not belong to the tenant
     */
    protected function validateTenantAccess(ServerRequestInterface $request): ?string
    {
        $tenantId = $request->getAttribute('tenant_id');

        if ($tenantId === null) {
            return null;
        }

        $tenantId = (string) $tenantId;

        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null) {
            throw new RuntimeException('Authentication required for tenant-scoped operations');
        }

        if (method_exists($identity, 'tenantIds') && !in_array($tenantId, $identity->tenantIds(), true)) {
            throw new RuntimeException('Access denied: identity does not belong to tenant');
        }

        return $tenantId;
    }
}
