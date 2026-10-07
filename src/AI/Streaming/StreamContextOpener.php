<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Override;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\Api\Internal;

use function fopen;
use function restore_error_handler;
use function set_error_handler;
use function stream_context_create;

/**
 * Opens a connection with PHP's native http stream wrapper.
 *
 * No curl, no Guzzle — the same constraint {@see \Pulsar\Http\Client\HttpClient}
 * works under.
 */
#[Internal(reason: 'Default opener for the AI streaming transport')]
final readonly class StreamContextOpener implements HttpStreamOpenerInterface
{
    /**
     * @param string $provider Provider name, used only in failure messages
     */
    public function __construct(private string $provider) {}

    #[Override]
    public function open(string $url, array $httpContextOptions): OpenedHttpStream
    {
        $context = stream_context_create(['http' => $httpContextOptions]);

        // Filled by the http wrapper in THIS scope once the response headers are
        // in; declared first so a failed open leaves a defined value.
        /** @var list<string> $http_response_header */
        $http_response_header = [];

        // A refused connection emits a PHP warning and returns false. Scope an
        // error handler around the open rather than reaching for `@`, matching
        // HttpHealthCheck; the `=== false` branch below is the failure path.
        set_error_handler(static fn(): bool => true);

        try {
            $handle = fopen($url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            throw AiStreamException::transportFailure($this->provider, $url, 'the connection could not be opened');
        }

        return new OpenedHttpStream($handle, $http_response_header);
    }
}
