<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Security\ProductionSafetyMode;
use Pulsar\Http\Message\Response;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Handles GET /studio/console/exceptions: exception explorer.
 */
#[Internal]
final readonly class ExceptionExplorerController
{
    use RendersStudioView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private EventStoreInterface $store,
        private ProductionSafetyMode $safetyMode,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(ServerRequestInterface $_request): Response
    {
        $events = $this->store->query(
            ['event_type' => ['exception']],
            limit: 100,
        );

        $dataJson = json_encode([
            'events' => $events,
            'show_traces' => $this->safetyMode->allowStackTraces(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $html = $this->renderStudioView('Exceptions - Pulsar Studio', 'console/exceptions', [
            'dataJson' => $dataJson,
        ]);

        return Response::html($html);
    }
}
