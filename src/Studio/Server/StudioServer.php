<?php

declare(strict_types=1);

namespace Pulsar\Studio\Server;

use Pulsar\Api\Internal;
use Pulsar\Config\StudioServerConfig;

use function sprintf;

/**
 * Wraps the PHP built-in development server for Studio.
 *
 * Manages the lifecycle of a `php -S` process that serves
 * the Studio UI and API endpoints.
 */
#[Internal]
final readonly class StudioServer
{
    public function __construct(
        private readonly StudioServerConfig $config,
        private readonly string $documentRoot,
        private readonly ?string $routerScript = null,
    ) {}

    /**
     * Start the development server (blocking).
     *
     * @return int Exit code from the server process
     */
    public function start(): int
    {
        $command = sprintf(
            'php -S %s:%d -t %s',
            $this->config->host,
            $this->config->port,
            escapeshellarg($this->documentRoot),
        );

        if ($this->routerScript !== null) {
            $command .= ' ' . escapeshellarg($this->routerScript);
        }

        passthru($command, $exitCode);

        return $exitCode;
    }

    /**
     * Get the URL the server will listen on.
     */
    public function url(): string
    {
        return sprintf('http://%s:%d', $this->config->host, $this->config->port);
    }

    /**
     * Get the Studio console URL.
     */
    public function consoleUrl(): string
    {
        return $this->url() . '/studio/console';
    }
}
