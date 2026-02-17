<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * Middleware that scores each request for bot probability.
 *
 * Attaches the BotScore to the request as an attribute for downstream
 * use, and optionally blocks requests exceeding a configurable threshold.
 */
#[Api(since: '1.0.0')]
final readonly class BotDetectionMiddleware implements MiddlewareInterface
{
    public const string BOT_SCORE_ATTR = '_pulsar_bot_score';

    public function __construct(
        private BotDetector $detector,
        private LoggerInterface $logger,
        private int $blockThreshold = 0,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $score = $this->detector->analyze($request);

        $request = $request->withAttribute(self::BOT_SCORE_ATTR, $score);

        if ($this->blockThreshold > 0 && $score->isBot($this->blockThreshold)) {
            $this->logger->warning('Bot detected: blocking request', [
                'score' => $score->score,
                'threshold' => $this->blockThreshold,
                'signals' => $score->signals,
                'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? '',
            ]);

            return Response::text('Forbidden', 403);
        }

        return $handler->handle($request);
    }
}
