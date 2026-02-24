<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Support\Toast;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Security\Session\SessionInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function is_array;
use function json_encode;
use function mb_strlen;
use function mb_substr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Reads flash-stored Toast notifications from the session and encodes
 * them into the X-CMS-Toast response header for client-side rendering.
 *
 * Limits: at most 10 toasts per response, each message truncated to 500 characters.
 */
#[Internal(reason: 'CMS middleware — not a public API surface')]
final readonly class ToastMiddleware implements MiddlewareInterface
{
    private const int MAX_TOASTS = 10;
    private const int MAX_MESSAGE_LENGTH = 500;

    public function __construct(
        private SessionInterface $session,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->session->has('_toasts')) {
            return $response;
        }

        /** @var mixed $raw */
        $raw = $this->session->get('_toasts', []);

        $toasts = array_values(array_filter(
            is_array($raw) ? $raw : [],
            static fn(mixed $item): bool => $item instanceof Toast,
        ));

        // Clear flash data regardless of whether valid toasts were found
        $this->session->remove('_toasts');

        if ($toasts === []) {
            return $response;
        }

        // Cap the number of toasts to prevent oversized headers
        $toasts = array_slice($toasts, 0, self::MAX_TOASTS);

        // Truncate individual toast messages to prevent oversized headers
        $toasts = array_map(
            static fn(Toast $toast): Toast => mb_strlen($toast->message) > self::MAX_MESSAGE_LENGTH
                ? $toast->withMessage(mb_substr($toast->message, 0, self::MAX_MESSAGE_LENGTH))
                : $toast,
            $toasts,
        );

        return $response->withHeader(
            'X-CMS-Toast',
            json_encode($toasts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }
}
