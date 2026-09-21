<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Pulsar\AI\Exception\AiStreamException;
use Pulsar\Api\Internal;

/**
 * Opens one HTTP connection and stops there.
 *
 * Everything else in {@see StreamContextTransport} — which statuses are
 * acceptable, how long a silence may last, how the body is chunked — is policy
 * that can be decided and proved without a socket. This is the one line that
 * cannot, so it is the one thing behind a seam.
 */
#[Internal(reason: 'Transport detail of the AI streaming stack')]
interface HttpStreamOpenerInterface
{
    /**
     * @param array<string, mixed> $httpContextOptions The `http` stream context options
     *
     * @throws AiStreamException When the connection cannot be opened
     */
    public function open(string $url, array $httpContextOptions): OpenedHttpStream;
}
