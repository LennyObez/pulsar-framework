<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Forms\FormSubmissionServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function explode;
use function is_array;
use function is_string;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * Public-facing controller for form submissions.
 *
 * Processes POST requests from contact forms and other CMS form blocks,
 * delegates to the form submission service, and returns a redirect.
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class FormSubmissionController
{
    public function __construct(
        private FormSubmissionServiceInterface $formService,
    ) {}

    /**
     * Handle a form submission POST request.
     */
    public function submit(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        // Separate internal metadata from user-submitted form data.
        // All _-prefixed fields are extracted here because the stripping loop
        // below removes them from $formData before it reaches spam detectors.
        /** @var mixed $rawCsrf */
        $rawCsrf = $body['_csrf_token'] ?? null;
        /** @var mixed $rawHp */
        $rawHp = $body['_hp_field'] ?? null;
        /** @var mixed $rawPowNonce */
        $rawPowNonce = $body['_pow_nonce'] ?? null;
        /** @var mixed $rawPowChallenge */
        $rawPowChallenge = $body['_pow_challenge'] ?? null;
        /** @var mixed $rawFormRenderedAt */
        $rawFormRenderedAt = $body['_form_rendered_at'] ?? null;
        /** @var mixed $rawFormBlockId */
        $rawFormBlockId = $body['_form_block_id'] ?? null;
        /** @var mixed $rawContentId */
        $rawContentId = $body['_content_id'] ?? null;
        /** @var mixed $rawTenantId */
        $rawTenantId = $request->getAttribute('tenant_id');
        $meta = [
            '_csrf_token' => is_string($rawCsrf) ? $rawCsrf : '',
            '_hp_field' => is_string($rawHp) ? $rawHp : '',
            '_pow_nonce' => is_string($rawPowNonce) ? $rawPowNonce : '',
            '_pow_challenge' => is_string($rawPowChallenge) ? $rawPowChallenge : '',
            '_form_rendered_at' => $rawFormRenderedAt,
            'ip' => self::extractIp($request),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'form_block_id' => is_string($rawFormBlockId) ? $rawFormBlockId : '',
            'content_id' => is_string($rawContentId) ? $rawContentId : '',
            'tenant_id' => is_string($rawTenantId) ? $rawTenantId : null,
        ];

        // Strip internal fields from user data
        /** @var array<string, mixed> $formData */
        $formData = [];

        /** @var mixed $value */
        foreach ($body as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            $formData[$key] = $value;
        }

        /** @var mixed $rawRedirect */
        $rawRedirect = $body['_redirect'] ?? null;
        $redirectUrl = self::sanitizeRedirectUrl(
            is_string($rawRedirect) ? $rawRedirect : '/',
        );

        try {
            /** @var array{_csrf_token?: string, ip?: string, user_agent?: string, form_block_id?: string, content_id?: string, tenant_id?: string|null} $submitMeta */
            $submitMeta = $meta;
            $this->formService->submit($formData, $submitMeta);

            return Response::redirect($redirectUrl, 303)
                ->withHeader('X-Form-Status', 'success');
        } catch (RuntimeException) {
            return Response::redirect($redirectUrl, 303)
                ->withHeader('X-Form-Status', 'error')
                ->withHeader('X-Form-Error', 'Form submission failed');
        }
    }

    /**
     * Validate that a redirect URL is a safe relative path.
     *
     * Rejects absolute URLs, protocol-relative URLs, and anything that
     * could lead to an open redirect vulnerability.
     */
    private static function sanitizeRedirectUrl(string $url): string
    {
        if (!str_starts_with($url, '/') || str_contains($url, '//')) {
            return '/';
        }

        return $url;
    }

    /**
     * Extract the client IP address from the request.
     *
     * Only trusts X-Forwarded-For when the request has been flagged as coming
     * through a trusted proxy (via the `trusted_proxy` attribute). Otherwise,
     * falls back to REMOTE_ADDR from server params.
     */
    private static function extractIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        /** @var mixed $rawRemoteAddr */
        $rawRemoteAddr = $serverParams['REMOTE_ADDR'] ?? null;
        $remoteAddr = is_string($rawRemoteAddr) ? $rawRemoteAddr : '0.0.0.0';

        // Only trust X-Forwarded-For if the request came through a known proxy
        if ($request->getAttribute('trusted_proxy') === true) {
            $forwardedFor = $request->getHeaderLine('X-Forwarded-For');

            if ($forwardedFor !== '') {
                $parts = explode(',', $forwardedFor);

                return trim($parts[0]);
            }
        }

        return $remoteAddr;
    }
}
