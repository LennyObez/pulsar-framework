<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Exception;

use Pulsar\Api\Api;
use RuntimeException;

/**
 * OAuth2 protocol exception with RFC 6749 error codes.
 * @api
 */
#[Api(since: '1.0.0')]
final class OAuth2Exception extends RuntimeException
{
    private function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatusCode = 400,
        private readonly ?string $errorUri = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidRequest(string $detail): self
    {
        return new self('invalid_request', $detail);
    }

    public static function invalidClient(string $detail = 'Client authentication failed'): self
    {
        return new self('invalid_client', $detail, 401);
    }

    public static function invalidGrant(string $detail = 'The provided grant is invalid'): self
    {
        return new self('invalid_grant', $detail);
    }

    public static function unauthorizedClient(string $detail = 'The client is not authorized for this grant type'): self
    {
        return new self('unauthorized_client', $detail);
    }

    public static function unsupportedGrantType(string $grantType): self
    {
        return new self('unsupported_grant_type', "Grant type '$grantType' is not supported");
    }

    public static function invalidScope(string $detail = 'The requested scope is invalid'): self
    {
        return new self('invalid_scope', $detail);
    }

    public static function accessDenied(string $detail = 'Access denied'): self
    {
        return new self('access_denied', $detail, 403);
    }

    public static function serverError(string $detail = 'An unexpected error occurred'): self
    {
        return new self('server_error', $detail, 500);
    }

    public static function registrationDisabled(): self
    {
        return new self('invalid_request', 'Dynamic client registration is disabled', 403);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function errorUri(): ?string
    {
        return $this->errorUri;
    }

    /**
     * Format as RFC 6749 error response body.
     *
     * @return array<string, string>
     */
    public function toErrorResponse(): array
    {
        $response = [
            'error' => $this->errorCode,
            'error_description' => $this->getMessage(),
        ];

        if ($this->errorUri !== null) {
            $response['error_uri'] = $this->errorUri;
        }

        return $response;
    }
}
