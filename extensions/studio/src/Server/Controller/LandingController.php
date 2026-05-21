<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Http\Message\Response;

use function implode;
use function sprintf;

/**
 * Handles GET /studio: the Studio landing page.
 */
#[Internal]
final readonly class LandingController
{
    use RendersStudioView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private StudioConfig $config,
        private ?EventStoreInterface $store = null,
    ) {}

    public function handle(ServerRequestInterface $_request): Response
    {
        $eventCount = $this->store?->count() ?? 0;
        $sizeBytes = $this->store?->sizeInBytes() ?? 0;
        $samplingPct = sprintf('%.0f%%', $this->config->samplingRate * 100.0);
        $retentionDays = $this->config->retention->maxAgeDays;
        $maxSizeMb = $this->config->retention->maxSizeMb;
        $collectors = $this->buildCollectorBadges();
        $storagePath = $this->config->storagePath;

        $html = $this->renderStudioView('Pulsar Studio', 'landing', [
            'formattedCount' => $this->formatNumber($eventCount),
            'formattedSize' => $this->formatBytes($sizeBytes),
            'samplingPct' => $samplingPct,
            'retentionDays' => $retentionDays,
            'maxSizeMb' => $maxSizeMb,
            'collectors' => $collectors,
            'storagePath' => $storagePath,
        ]);

        return Response::html($html);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return sprintf('%.1f KB', (float) $bytes / 1024.0);
        }

        return sprintf('%.1f MB', (float) $bytes / 1048576.0);
    }

    private function formatNumber(int $count): string
    {
        if ($count < 1000) {
            return (string) $count;
        }
        if ($count < 1000000) {
            return sprintf('%.1fK', (float) $count / 1000.0);
        }

        return sprintf('%.1fM', (float) $count / 1000000.0);
    }

    private function buildCollectorBadges(): string
    {
        $collectors = $this->config->collectors;
        $badges = [];

        $map = [
            'HTTP' => $collectors->http,
            'Database' => $collectors->database,
            'Logs' => $collectors->logs,
            'Exceptions' => $collectors->exceptions,
            'Scheduler' => $collectors->scheduler,
            'Flags' => $collectors->featureFlags,
        ];

        foreach ($map as $label => $enabled) {
            $class = $enabled ? 'collector-badge' : 'collector-badge disabled';
            $badges[] = sprintf('<span class="%s">%s</span>', $class, $label);
        }

        return implode('', $badges);
    }
}
