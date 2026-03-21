<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Psd2\Contracts\CertificateValidatorInterface;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function is_string;
use function str_contains;

/**
 * Middleware for PSD2 client certificate authentication (Art. 66-67).
 *
 * Validates the client certificate from the TLS handshake (passed via
 * server params or a proxy header), extracts PSD2-specific information,
 * and makes it available as a request attribute.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CertificateAuthenticationMiddleware implements MiddlewareInterface
{
    public const string CERT_ATTRIBUTE = '_psd2_certificate_info';
    public const string CERT_HEADER = 'X-SSL-Client-Cert';

    public function __construct(
        private CertificateValidatorInterface $validator,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $pemCertificate = $this->extractCertificate($request);

        if ($pemCertificate === null) {
            return $this->unauthorizedResponse($request, 'No client certificate provided');
        }

        try {
            $certInfo = $this->validator->validate($pemCertificate);
        } catch (Psd2Exception $e) {
            return $this->unauthorizedResponse($request, $e->getMessage());
        }

        if (!$this->validator->isAuthorized($certInfo->authorizationNumber)) {
            return $this->unauthorizedResponse($request, 'Provider is not authorized');
        }

        $request = $request->withAttribute(self::CERT_ATTRIBUTE, $certInfo);

        return $handler->handle($request);
    }

    private function extractCertificate(ServerRequestInterface $request): ?string
    {
        // Try server params first (direct TLS termination)
        $serverParams = $request->getServerParams();

        /** @var string|null $cert */
        $cert = $serverParams['SSL_CLIENT_CERT'] ?? null;

        if (is_string($cert) && $cert !== '') {
            return $cert;
        }

        // Fall back to proxy header
        $headerCert = $request->getHeaderLine(self::CERT_HEADER);

        if ($headerCert !== '') {
            return $headerCert;
        }

        return null;
    }

    private function unauthorizedResponse(ServerRequestInterface $request, string $reason): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(
                [
                    'error' => $reason,
                    'code' => 'certificate_authentication_failed',
                    'status' => 401,
                ],
                ResponseStatus::Unauthorized->value,
            );
        }

        return new Response(
            statusCode: ResponseStatus::Unauthorized->value,
            body: $reason,
        );
    }
}
