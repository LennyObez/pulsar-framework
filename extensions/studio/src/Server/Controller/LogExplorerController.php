<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Http\Message\Response;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Handles GET /studio/console/logs; log entry explorer.
 */
#[Internal]
final readonly class LogExplorerController
{
    use RendersStudioView;

    public function __construct(
        private EventStoreInterface $store,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(ServerRequestInterface $_request): Response
    {
        $events = $this->store->query(
            ['event_type' => ['log.entry']],
            limit: 100,
        );

        $dataJson = json_encode(['events' => $events], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $html = $this->renderStudioView('Logs - Pulsar Studio', 'console/logs', [
            'dataJson' => $dataJson,
        ]);

        return Response::html($html);
    }
}
