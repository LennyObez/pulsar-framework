<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Pulsar\Api\Internal;

/**
 * Adapts a PSR-18 HTTP client to the mail transport HTTP contract.
 *
 * When the application binds a {@see ClientInterface} (Guzzle, Symfony
 * HttpClient, etc.), MailWiring prefers this adapter over the built-in
 * {@see CurlMailHttpClient} so API-based mail transports reuse the
 * application's HTTP stack — its connection pooling, retries, proxy and
 * observability configuration, and test doubles — instead of a parallel
 * cURL path.
 *
 * A PSR-18 transport failure throws {@see \Psr\Http\Client\ClientExceptionInterface},
 * which the calling transport wraps in a driver-scoped MailException.
 */
#[Internal]
final readonly class Psr18MailHttpClient implements MailHttpClientInterface
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    #[Override]
    public function request(string $method, string $url, array $headers, string $body): MailHttpResponse
    {
        $request = $this->requestFactory
            ->createRequest($method, $url)
            ->withBody($this->streamFactory->createStream($body));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->client->sendRequest($request);

        return new MailHttpResponse(
            $response->getStatusCode(),
            (string) $response->getBody(),
        );
    }
}
