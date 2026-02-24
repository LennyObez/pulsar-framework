<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function is_array;
use function is_string;

/**
 * Honeypot middleware for comment spam detection.
 *
 * Checks for a hidden honeypot field in POST data. If the field is filled in
 * (only bots would fill a hidden field), the request is silently rejected
 * with a fake 200 success response to avoid tipping off the bot.
 *
 * @psalm-api Registered with the router middleware pipeline by the
 *            CmsCoreServiceProvider; not new'd by name.
 */
#[Internal(reason: 'CMS middleware; not a public API surface')]
final readonly class CommentHoneypotMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuditLoggerInterface $auditLogger,
        private string $honeypotField = 'website_url',
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (is_array($body)) {
            $honeypotValue = $body[$this->honeypotField] ?? null;

            if (is_string($honeypotValue) && $honeypotValue !== '') {
                $this->auditLogger->log(
                    AuditEvent::SecurityEvent,
                    AuditOutcome::Denied,
                    null,
                    'cms.comment.honeypot_triggered',
                    'CommentHoneypotMiddleware',
                    ['field' => $this->honeypotField],
                );

                // Return a fake success to not reveal detection to the bot
                return Response::json(
                    ['status' => 'success', 'message' => 'Comment submitted successfully'],
                    ResponseStatus::OK->value,
                );
            }
        }

        return $handler->handle($request);
    }
}
