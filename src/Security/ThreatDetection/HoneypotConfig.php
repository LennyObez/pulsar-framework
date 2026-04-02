<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for honeypot endpoint detection.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HoneypotConfig
{
    /** @var list<string> */
    public array $paths;

    /**
     * @param list<string> $paths           URI paths that only attackers would request
     * @param bool         $blockIp         Whether to return 403 (true) or fake 404 (false)
     * @param ThreatResponse $responseAction Action to recommend in the ThreatEvent
     */
    public function __construct(
        array $paths = [],
        public bool $blockIp = true,
        public ThreatResponse $responseAction = ThreatResponse::Block,
    ) {
        $this->paths = $paths !== [] ? $paths : self::defaultPaths();
    }

    /**
     * @return list<string>
     */
    private static function defaultPaths(): array
    {
        return [
            '/wp-login.php',
            '/wp-admin',
            '/administrator',
            '/admin/backup.sql',
            '/.env',
            '/phpinfo.php',
            '/.git/config',
            '/xmlrpc.php',
            '/wp-config.php.bak',
            '/server-status',
        ];
    }

    /**
     * @param array{
     *     paths?: list<string>,
     *     block_ip?: bool,
     *     response_action?: ThreatResponse|string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawAction = $data['response_action'] ?? null;
        $responseAction = $rawAction instanceof ThreatResponse
            ? $rawAction
            : ThreatResponse::tryFrom($rawAction ?? '') ?? ThreatResponse::Block;

        return new self(
            paths: $data['paths'] ?? [],
            blockIp: $data['block_ip'] ?? true,
            responseAction: $responseAction,
        );
    }
}
